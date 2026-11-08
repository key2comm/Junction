<?php

namespace App\Services;

use App\Models\RateImport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Applies a rate deck CSV to a deck.
 *
 *  - Every row is validated first; any error aborts the whole import, so a
 *    deck is never left half-updated.
 *  - A new rate for a prefix end-dates the rate in effect at its effective
 *    time and replaces any rates scheduled after it.
 *  - Rows identical to the open-ended rate in effect are left alone.
 *  - "replace" mode also end-dates prefixes missing from the file.
 *  - Columns are matched by header name, or by the admin's choice in the
 *    upload screen (RateImport::$mapping): see preview().
 *  - North American rows can give NPA and NXX instead of (or as well as) a
 *    prefix: prefix = prefix + NPA + NXX, with the country code (default 1)
 *    in front when the prefix cell is empty. A prefix already ending in its
 *    NPA+NXX is used as it is.
 *  - "Interval" is the billing increments, e.g. 60/60, 6/6 or 60 (= 60/60).
 *
 * The apply step is set-based SQL in one transaction (PostgreSQL), so decks of
 * hundreds of thousands of prefixes import in seconds.
 */
class RateImporter
{
    /** Accepted header names for each column (lower-case, spaces/dashes as _). */
    public const COLUMNS = [
        'prefix' => ['prefix', 'code', 'dial_code', 'dialcode', 'dialing_code', 'destination_code'],
        'npa' => ['npa', 'area_code', 'npa_code'],
        'nxx' => ['nxx', 'exchange', 'nxx_code', 'co_code'],
        'destination' => ['destination', 'description', 'name', 'country', 'dest'],
        'rate' => ['rate', 'price', 'rate_per_min', 'rate_per_minute', 'per_minute', 'cost'],
        'connect_fee' => ['connect_fee', 'connection_fee', 'setup_fee'],
        'interval' => ['interval', 'billing_interval', 'increments', 'billing_increments', 'billing'],
        'initial_increment' => ['initial_increment', 'min_duration', 'first_increment', 'initial'],
        'increment' => ['increment', 'billing_increment', 'next_increment'],
        'effective_from' => ['effective_from', 'date_effective', 'effective_date', 'effective', 'valid_from', 'start_date'],
    ];

    /** The fields an admin can match to file columns on the upload screen (in this order). */
    public const FIELDS = [
        'prefix' => 'Prefix', 'npa' => 'NPA', 'nxx' => 'NXX', 'interval' => 'Interval', 'rate' => 'Rate',
        'effective_from' => 'Date effective', 'destination' => 'Destination', 'connect_fee' => 'Connect fee',
    ];

    public const PREVIEW_ROWS = 5;

    public const MAX_ERRORS = 50;

    public const NOTICE_DAYS = 7;

    public function run(RateImport $import): void
    {
        $import->update(['status' => 'running', 'started_at' => now(), 'errors' => null, 'stats' => null]);

        try {
            [$rows, $errors] = $this->parse(Storage::disk('local')->path($import->path), CarbonImmutable::parse($import->effective_from), $import->mapping);

            if ($errors !== []) {
                $import->update(['status' => 'failed', 'errors' => $errors, 'stats' => ['rows' => count($rows)], 'finished_at' => now()]);

                return;
            }
            if ($rows === []) {
                $import->update(['status' => 'failed', 'errors' => ['The file has no rate rows.'], 'finished_at' => now()]);

                return;
            }

            $stats = $this->apply($import, $rows);
            $import->update(['status' => 'done', 'stats' => $stats, 'finished_at' => now()]);
        } catch (Throwable $e) {
            $import->update(['status' => 'failed', 'errors' => [$e->getMessage()], 'finished_at' => now()]);
        }
    }

    /**
     * The file's header and first rows, with a suggested column for each field,
     * for the upload screen.
     *
     * @return array{header: list<string>, rows: list<list<string>>, suggested: array<string, int|null>}
     */
    public function preview(string $file): array
    {
        $handle = @fopen($file, 'r');
        if ($handle === false) {
            throw new RuntimeException('Could not open the uploaded file.');
        }
        try {
            $first = preg_replace('/^\xEF\xBB\xBF/', '', (string) fgets($handle));
            $delimiter = $this->detectDelimiter($first);
            $header = array_map(fn ($c) => mb_substr(trim((string) $c), 0, 100), str_getcsv(rtrim($first, "\r\n"), $delimiter, '"', ''));
            $rows = [];
            while (count($rows) < self::PREVIEW_ROWS && ($cells = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
                if ($cells !== [null] && implode('', $cells) !== '') {
                    $rows[] = array_map(fn ($c) => mb_substr(trim((string) $c), 0, 100), $cells);
                }
            }
            $found = $this->mapHeader($header, required: false);
            $suggested = [];
            foreach (array_keys(self::FIELDS) as $field) {
                $suggested[$field] = $found[$field] ?? null;
            }

            return ['header' => $header, 'rows' => $rows, 'suggested' => $suggested];
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  array{has_header?: bool, columns?: array<string, int|null>, npa_country?: string}|null  $mapping  the admin's column choices
     * @return array{0: list<array<string, mixed>>, 1: list<string>}
     */
    public function parse(string $file, CarbonImmutable $defaultEffective, ?array $mapping = null): array
    {
        $handle = @fopen($file, 'r');
        if ($handle === false) {
            throw new RuntimeException('Could not open the uploaded file.');
        }

        try {
            $first = (string) fgets($handle);
            $first = preg_replace('/^\xEF\xBB\xBF/', '', $first); // UTF-8 BOM
            $delimiter = $this->detectDelimiter($first);
            if ($mapping !== null) {
                $map = array_filter($mapping['columns'] ?? [], fn ($i) => $i !== null && $i !== '');
                $map = array_map('intval', $map);
                self::requireColumns($map);
                if (! ($mapping['has_header'] ?? true)) {
                    rewind($handle); // the first line is data
                    $bom = fread($handle, 3);
                    if ($bom !== "\xEF\xBB\xBF") {
                        rewind($handle);
                    }
                }
            } else {
                $map = $this->mapHeader(str_getcsv($first, $delimiter, '"', ''));
            }
            $country = preg_replace('/\D/', '', (string) ($mapping['npa_country'] ?? '1'));

            $rows = [];
            $errors = [];
            $seen = [];
            $line = 1;
            while (($cells = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
                $line++;
                if ($cells === [null] || implode('', $cells) === '') {
                    continue; // blank line
                }
                $row = $this->row($cells, $map, $defaultEffective, $country, $error);
                if ($error !== null) {
                    $errors[] = "Line {$line}: {$error}";
                } else {
                    $key = $row['prefix'].'|'.$row['effective_from'];
                    if (isset($seen[$key])) {
                        $errors[] = "Line {$line}: prefix {$row['prefix']} is already on line {$seen[$key]}.";
                    } else {
                        $seen[$key] = $line;
                        $rows[] = $row;
                    }
                }
                if (count($errors) >= self::MAX_ERRORS) {
                    $errors[] = 'Stopped after '.self::MAX_ERRORS.' errors.';
                    break;
                }
            }

            return [$rows, $errors];
        } finally {
            fclose($handle);
        }
    }

    private function detectDelimiter(string $line): string
    {
        $counts = [',' => substr_count($line, ','), ';' => substr_count($line, ';'), "\t" => substr_count($line, "\t")];
        arsort($counts);

        return (string) array_key_first($counts);
    }

    /** Every import needs a rate, and a prefix or an NPA. */
    private static function requireColumns(array $map, array $header = []): void
    {
        $found = $header ? ' (found: '.implode(', ', array_filter($header)).')' : '';
        if (! isset($map['rate'])) {
            throw new RuntimeException("Choose the column that holds the rate{$found}.");
        }
        if (! isset($map['prefix']) && ! isset($map['npa'])) {
            throw new RuntimeException("Choose the column that holds the prefix, or the NPA (and NXX){$found}.");
        }
    }

    /** @return array<string, int> column => index */
    private function mapHeader(array $header, bool $required = true): array
    {
        $map = [];
        foreach ($header as $i => $name) {
            $name = strtolower(trim(preg_replace('/[\s\-]+/', '_', (string) $name)));
            foreach (self::COLUMNS as $column => $aliases) {
                if (! isset($map[$column]) && in_array($name, $aliases, true)) {
                    $map[$column] = $i;
                }
            }
        }
        if ($required) {
            if (! isset($map['rate']) || (! isset($map['prefix']) && ! isset($map['npa']))) {
                throw new RuntimeException('The header row needs a "prefix" column (or "npa") and a "rate" column (found: '.implode(', ', array_filter($header)).').');
            }
        }

        return $map;
    }

    /**
     * The dialled prefix of a row: the prefix, plus NPA and NXX for North
     * American rows (country code in front when there is no prefix).
     */
    public static function prefixFor(string $prefix, string $npa, string $nxx, string $country = '1'): string
    {
        $clean = fn (string $v) => preg_replace('/[\s+\-()]/', '', $v);
        $prefix = $clean($prefix);
        $tail = $clean($npa).$clean($nxx);
        if ($clean($npa) === '') {
            return $prefix;
        }
        if ($prefix === '') {
            return $country.$tail;
        }

        return str_ends_with($prefix, $tail) ? $prefix : $prefix.$tail;
    }

    private function row(array $cells, array $map, CarbonImmutable $defaultEffective, string $country, ?string &$error): array
    {
        $error = null;
        $get = fn (string $column) => isset($map[$column]) ? trim((string) ($cells[$map[$column]] ?? '')) : '';

        $prefix = self::prefixFor($get('prefix'), $get('npa'), $get('nxx'), $country);
        if (! preg_match('/^[0-9]{1,32}$/', $prefix)) {
            $shown = trim($get('prefix').' '.$get('npa').' '.$get('nxx'));
            $error = "prefix \"{$shown}\" must be 1-32 digits.";

            return [];
        }

        $numbers = [];
        foreach (['rate' => null, 'connect_fee' => '0'] as $column => $default) {
            // A leading currency sign is fine ("$0.012"); commas are not ("0,02" is ambiguous, so it's rejected).
            $value = trim(preg_replace('/^[$€£]\s*/u', '', $get($column)));
            if ($value === '' && $default !== null) {
                $value = $default;
            }
            if (! is_numeric($value) || (float) $value < 0 || (float) $value > 999999) {
                $error = "{$column} \"{$value}\" must be a number of 0 or more.";

                return [];
            }
            $numbers[$column] = $value;
        }

        // "Interval" (60/60, 6/6, 60) sets both increments; otherwise the two columns, default 60/60.
        $interval = $get('interval');
        $parts = $interval === '' ? [] : preg_split('/\s*[\/\-:x]\s*/i', $interval);
        $given = $parts === [] ? [] : ['initial_increment' => $parts[0], 'increment' => $parts[1] ?? $parts[0]];
        $increments = [];
        foreach (['initial_increment' => 60, 'increment' => 60] as $column => $default) {
            $value = $given[$column] ?? ($get($column) === '' ? (string) $default : $get($column));
            if (! ctype_digit($value) || (int) $value < 1 || (int) $value > 3600) {
                $error = $given !== []
                    ? "interval \"{$interval}\" must be like 60/60, 6/6 or 60 (seconds, 1-3600)."
                    : "{$column} \"{$value}\" must be a whole number of seconds (1-3600).";

                return [];
            }
            $increments[$column] = (int) $value;
        }

        $effective = $defaultEffective;
        if ($get('effective_from') !== '') {
            try {
                $effective = CarbonImmutable::parse($get('effective_from'), 'UTC');
            } catch (Throwable) {
                $error = "effective_from \"{$get('effective_from')}\" is not a date.";

                return [];
            }
        }

        $destination = $get('destination');

        return [
            'prefix' => $prefix,
            'destination' => $destination === '' ? null : mb_substr($destination, 0, 255),
            'rate' => $numbers['rate'],
            'connect_fee' => $numbers['connect_fee'],
            'initial_increment' => $increments['initial_increment'],
            'increment' => $increments['increment'],
            'effective_from' => $effective->utc()->format('Y-m-d H:i:sP'),
        ];
    }

    /**
     * Apply validated rows (also used by App\Jobs\BuildTariff). Mode "build"
     * behaves like "replace": the rows are the whole deck.
     *
     * @return array<string, int>
     */
    public function apply(RateImport $import, array $rows): array
    {
        $deck = $import->rate_deck_id;
        $effective = CarbonImmutable::parse($import->effective_from)->utc()->format('Y-m-d H:i:sP');
        $noticeCutoff = now()->addDays(self::NOTICE_DAYS)->utc()->format('Y-m-d H:i:sP');

        return DB::transaction(function () use ($import, $rows, $deck, $effective, $noticeCutoff) {
            // ON COMMIT DROP only fires on a real commit; inside an enclosing
            // transaction (or savepoint) a previous import's table may remain.
            DB::statement('DROP TABLE IF EXISTS pg_temp.rate_import_rows');
            DB::statement(<<<'SQL'
                CREATE TEMP TABLE rate_import_rows (
                    prefix varchar(32) NOT NULL,
                    destination varchar(255),
                    rate numeric(12, 6) NOT NULL,
                    connect_fee numeric(12, 6) NOT NULL,
                    initial_increment smallint NOT NULL,
                    increment smallint NOT NULL,
                    effective_from timestamptz NOT NULL,
                    prev_rate numeric(12, 6),
                    unchanged boolean NOT NULL DEFAULT false
                ) ON COMMIT DROP
            SQL);
            foreach (array_chunk($rows, 1000) as $chunk) {
                DB::table('rate_import_rows')->insert($chunk);
            }
            DB::statement('CREATE INDEX ON rate_import_rows (prefix)');
            DB::statement('ANALYZE rate_import_rows');

            // 1. Rates scheduled at or after a new row's effective time are superseded.
            DB::delete(
                'DELETE FROM rates r USING rate_import_rows t
                 WHERE r.rate_deck_id = ? AND r.prefix = t.prefix AND r.effective_from >= t.effective_from',
                [$deck],
            );
            if (in_array($import->mode, ['replace', 'build'], true)) {
                DB::delete(
                    'DELETE FROM rates r WHERE r.rate_deck_id = ? AND r.effective_from >= ?
                     AND NOT EXISTS (SELECT 1 FROM rate_import_rows t WHERE t.prefix = r.prefix)',
                    [$deck, $effective],
                );
            }

            // 2. Note the rate each row replaces, and whether nothing actually changes.
            DB::update(
                'UPDATE rate_import_rows t
                 SET prev_rate = r.rate,
                     unchanged = (r.effective_to IS NULL AND r.rate = t.rate AND r.connect_fee = t.connect_fee
                                  AND r.initial_increment = t.initial_increment AND r.increment = t.increment
                                  AND r.destination IS NOT DISTINCT FROM t.destination)
                 FROM rates r
                 WHERE r.rate_deck_id = ? AND r.prefix = t.prefix
                   AND (r.effective_to IS NULL OR r.effective_to > t.effective_from)',
                [$deck],
            );

            // 3. End the rates in effect for every changed prefix.
            DB::update(
                'UPDATE rates r SET effective_to = t.effective_from, updated_at = now()
                 FROM rate_import_rows t
                 WHERE r.rate_deck_id = ? AND r.prefix = t.prefix AND NOT t.unchanged
                   AND (r.effective_to IS NULL OR r.effective_to > t.effective_from)',
                [$deck],
            );

            // 4. Insert the new rates.
            $inserted = DB::affectingStatement(
                'INSERT INTO rates (rate_deck_id, prefix, destination, rate, connect_fee, initial_increment,
                                    increment, effective_from, created_at, updated_at)
                 SELECT ?, prefix, destination, rate, connect_fee, initial_increment, increment, effective_from, now(), now()
                 FROM rate_import_rows WHERE NOT unchanged',
                [$deck],
            );

            // 5. Replace mode: prefixes missing from the file stop at the import's effective time.
            $ended = 0;
            if (in_array($import->mode, ['replace', 'build'], true)) {
                $ended = DB::update(
                    'UPDATE rates r SET effective_to = ?, updated_at = now()
                     WHERE r.rate_deck_id = ? AND r.effective_from < ?
                       AND (r.effective_to IS NULL OR r.effective_to > ?)
                       AND NOT EXISTS (SELECT 1 FROM rate_import_rows t WHERE t.prefix = r.prefix)',
                    [$effective, $deck, $effective, $effective],
                );
            }

            $counts = DB::selectOne(
                'SELECT count(*) FILTER (WHERE unchanged) AS unchanged,
                        count(*) FILTER (WHERE NOT unchanged AND prev_rate IS NULL) AS added,
                        count(*) FILTER (WHERE NOT unchanged AND prev_rate IS NOT NULL) AS changed,
                        count(*) FILTER (WHERE NOT unchanged AND rate > prev_rate AND effective_from < ?) AS increases_within_notice
                 FROM rate_import_rows',
                [$noticeCutoff],
            );

            return [
                'rows' => count($rows),
                'added' => (int) $counts->added,
                'changed' => (int) $counts->changed,
                'unchanged' => (int) $counts->unchanged,
                'ended' => $ended,
                'inserted' => $inserted,
                'increases_within_notice' => (int) $counts->increases_within_notice,
            ];
        });
    }
}
