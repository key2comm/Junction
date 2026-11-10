<?php

namespace App\Services;

use App\Models\Account;
use App\Models\PbxDid;
use App\Support\Languages;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Routing -> DIDs -> Import: a CSV of DIDs, read in two steps like rate
 * imports. preview() shows the file and suggests a column for each field;
 * run() applies the admin's column choices. Every row is checked first and
 * any problem stops the import (nothing is changed), so a file is either
 * imported whole or not at all.
 *
 * Customers are matched by account code, providers by code or name, and the
 * destination is a PBX/Device ID (shown on the DID form) or an extension/IVR
 * number on the customer's built-in PBX. Columns can be left out in favour of
 * one value for every row (the defaults).
 */
class DidImporter
{
    public const MAX_ROWS = 10000;

    public const PREVIEW_ROWS = 5;

    public const FIELDS = [
        'number' => 'DID number',
        'customer' => 'Customer (account code)',
        'provider' => 'Provider (carrier code or name)',
        'device_id' => 'PBX/Device ID',
        'extension' => 'Extension / IVR number',
        'language' => 'Language (en, fr...)',
        'status' => 'Status (active, deactivated, banned)',
        'status_reason' => 'Status reason',
        'comment' => 'Comment',
    ];

    /** Header names recognised for each field (lower case, spaces/underscores ignored). */
    private const ALIASES = [
        'number' => ['number', 'did', 'didnumber', 'phonenumber', 'phone', 'tn', 'telephonenumber'],
        'customer' => ['customer', 'customercode', 'account', 'accountcode', 'customernumber', 'client'],
        'provider' => ['provider', 'carrier', 'vendor', 'providercode', 'carriercode'],
        'device_id' => ['deviceid', 'device', 'endpointid', 'pbxdeviceid', 'pbxid', 'destinationdevice'],
        'extension' => ['extension', 'ext', 'ivr', 'pbxnumber', 'destinationnumber'],
        'language' => ['language', 'lang'],
        'status' => ['status', 'state'],
        'status_reason' => ['statusreason', 'reason', 'banreason'],
        'comment' => ['comment', 'comments', 'description', 'notes', 'note'],
    ];

    public const SAMPLE = "number,customer,provider,device_id,extension,language,status,status_reason,comment\n"
        ."14165550100,ACME01,CARRIER1,12,,en,active,,Main line\n"
        ."14165550101,ACME01,CARRIER1,,1001,fr,active,,Reception (extension 1001)\n"
        ."4165550102,ACME01,CARRIER1,12,,en,banned,Unpaid invoice,10 digits: 1 is added\n";

    public function __construct(private Pbx $pbx) {}

    /** @return array{header: list<string>, rows: list<list<string>>, suggested: array<string, int|null>, count: int} */
    public function preview(string $file): array
    {
        $all = $this->read($file);
        $header = $all[0] ?? [];
        $suggested = [];
        foreach (self::ALIASES as $field => $names) {
            $suggested[$field] = null;
            foreach ($header as $i => $name) {
                if (in_array(preg_replace('/[\s_\-\/]+/', '', strtolower($name)), $names, true)) {
                    $suggested[$field] = $i;
                    break;
                }
            }
        }

        return [
            'header' => array_map(fn ($c) => mb_substr($c, 0, 100), $header),
            'rows' => array_map(fn ($r) => array_map(fn ($c) => mb_substr($c, 0, 100), $r), array_slice($all, 1, self::PREVIEW_ROWS)),
            'suggested' => $suggested,
            'count' => max(0, count($all) - 1),
        ];
    }

    /**
     * @param  array{has_header: bool, columns: array<string, int|null>, defaults: array<string, mixed>, existing: string, add_one: bool}  $mapping
     * @return array{created: int, updated: int, skipped: int}
     *
     * @throws ValidationException listing the rows with problems (nothing is imported)
     */
    public function run(string $file, array $mapping): array
    {
        $rows = $this->read($file);
        $first = $mapping['has_header'] ? 2 : 1; // line number of the first data row
        if ($mapping['has_header']) {
            array_shift($rows);
        }
        if ($rows === []) {
            throw ValidationException::withMessages(['file' => 'The file has no DIDs.']);
        }
        if (count($rows) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['file' => 'At most '.self::MAX_ROWS.' DIDs per import.']);
        }
        $col = $mapping['columns'];
        $defaults = $mapping['defaults'];
        if (($col['number'] ?? null) === null) {
            throw ValidationException::withMessages(['columns.number' => 'Choose the column with the DID numbers.']);
        }

        $customers = Account::query()->where('is_customer', true)->get(['id', 'code', 'name', 'status']);
        $byCode = $customers->keyBy(fn ($a) => strtolower($a->code));
        $providers = Account::query()->where('is_vendor', true)->get(['id', 'code', 'name']);
        $providerBy = $providers->keyBy(fn ($a) => strtolower($a->code))->union($providers->keyBy(fn ($a) => strtolower($a->name)));
        $existing = PbxDid::query()->get(['id', 'number', 'account_id', 'destination'])->keyBy('number');

        $errors = [];
        $seen = [];
        $plan = [];
        foreach ($rows as $n => $cells) {
            $line = $n + $first;
            $get = fn (string $field) => ($i = $col[$field] ?? null) === null ? '' : trim((string) ($cells[$i] ?? ''));
            $fail = function (string $message) use (&$errors, $line) {
                $errors[] = "Line {$line}: {$message}";
            };

            $raw = $get('number');
            $number = preg_replace('/\D/', '', $raw);
            if ($mapping['add_one'] && preg_match('/^[2-9]\d{2}[2-9]\d{6}$/', $number)) {
                $number = '1'.$number;
            }
            if (! preg_match('/^\d{7,20}$/', $number)) {
                $fail($raw === '' ? 'no DID number.' : "{$raw} is not a valid number (7-20 digits, international format).");

                continue;
            }
            if (isset($seen[$number])) {
                $fail("{$number} is also on line {$seen[$number]}.");

                continue;
            }
            $seen[$number] = $line;
            $current = $existing[$number] ?? null;
            if ($current && $mapping['existing'] === 'skip') {
                $plan[] = ['skip' => true];

                continue;
            }

            $code = $get('customer');
            $account = $code !== '' ? ($byCode[strtolower($code)] ?? null) : $customers->firstWhere('id', (int) ($defaults['account_id'] ?? 0));
            if (! $account) {
                $fail($code !== '' ? "no customer with account code {$code}." : 'no customer (add a Customer column or choose one for all rows).');

                continue;
            }

            $p = $get('provider');
            $provider = $p !== '' ? ($providerBy[strtolower($p)] ?? null) : $providers->firstWhere('id', (int) ($defaults['provider_account_id'] ?? 0));
            if (! $provider) {
                $fail($p !== '' ? "no carrier with code or name {$p}." : 'no provider (add a Provider column or choose one for all rows).');

                continue;
            }

            $extension = $get('extension');
            // The "all rows" device only fills rows that name neither a device nor an extension.
            $device = $get('device_id') !== '' ? $get('device_id') : ($extension === '' ? (string) ($defaults['device_id'] ?? '') : '');
            if ($device !== '' && $extension !== '') {
                $fail('give a PBX/Device ID or an extension, not both.');

                continue;
            }
            try {
                if ($device !== '') {
                    $destination = $this->pbx->destination($account, ['type' => 'endpoint', 'target' => $device], 'destination');
                } elseif ($extension !== '') {
                    $destination = $this->pbx->destination($account, ['type' => 'number', 'target' => $extension], 'destination');
                } elseif ($current && $current->account_id === $account->id) {
                    $destination = $current->destination; // updating: keep where it goes
                } else {
                    $fail('no destination (a PBX/Device ID or an extension).');

                    continue;
                }
            } catch (ValidationException $e) {
                $message = collect($e->errors())->flatten()->first();
                $fail(($device !== '' ? "PBX/Device ID {$device}: " : "extension {$extension}: ").lcfirst((string) $message));

                continue;
            }

            $language = strtolower($get('language') ?: (string) ($defaults['language'] ?? Languages::DEFAULT));
            if (! isset(Languages::ALL[$language])) {
                $fail("unknown language {$language} (use a 2-letter code such as en or fr).");

                continue;
            }
            $status = strtolower($get('status') ?: (string) ($defaults['status'] ?? 'active'));
            if (! isset(PbxDid::STATUSES[$status])) {
                $fail("unknown status {$status} (active, deactivated or banned).");

                continue;
            }
            $reason = $get('status_reason');
            if ($status === 'banned' && $reason === '') {
                $fail('a banned DID needs a status reason.');

                continue;
            }

            $plan[] = ['skip' => false, 'id' => $current?->id, 'data' => [
                'number' => $number, 'account_id' => $account->id, 'provider_account_id' => $provider->id, 'destination' => $destination,
                'language' => $language, 'status' => $status, 'status_reason' => $status === 'active' ? null : (mb_substr($reason, 0, 255) ?: null),
                'description' => mb_substr($get('comment'), 0, 255) ?: null,
            ]];
        }

        if ($errors !== []) {
            $shown = array_slice($errors, 0, 20);
            if (count($errors) > 20) {
                $shown[] = 'and '.(count($errors) - 20).' more.';
            }
            // One key per line: Inertia passes on the first message of each key.
            throw ValidationException::withMessages(collect($shown)->mapWithKeys(fn ($m, $i) => ["rows.{$i}" => $m])->all());
        }

        return DB::transaction(function () use ($plan, $col) {
            $result = ['created' => 0, 'updated' => 0, 'skipped' => 0];
            foreach ($plan as $p) {
                if ($p['skip']) {
                    $result['skipped']++;
                } elseif ($p['id']) {
                    $data = $p['data'];
                    if (($col['comment'] ?? null) === null) {
                        unset($data['description']); // no Comment column: keep the current one
                    }
                    PbxDid::query()->whereKey($p['id'])->first()->update($data);
                    $result['updated']++;
                } else {
                    PbxDid::create($p['data']);
                    $result['created']++;
                }
            }

            return $result;
        });
    }

    /** @return list<list<string>> every non-empty row, cells trimmed */
    private function read(string $file): array
    {
        $handle = @fopen($file, 'r');
        if ($handle === false) {
            throw new RuntimeException('Could not open the uploaded file.');
        }
        try {
            $first = preg_replace('/^\xEF\xBB\xBF/', '', (string) fgets($handle));
            $counts = [',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t")];
            arsort($counts);
            $delimiter = (string) array_key_first($counts);
            $rows = [];
            $push = function (array $cells) use (&$rows) {
                $cells = array_map(fn ($c) => trim((string) $c), $cells);
                if (implode('', $cells) !== '') {
                    $rows[] = $cells;
                }
            };
            if (trim($first) !== '') {
                $push(str_getcsv(rtrim($first, "\r\n"), $delimiter, '"', ''));
            }
            while (($cells = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
                if ($cells !== [null]) {
                    $push($cells);
                }
                if (count($rows) > self::MAX_ROWS + 1) {
                    break;
                }
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }
}
