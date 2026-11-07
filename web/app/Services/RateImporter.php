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
 *
 * The apply step is set-based SQL in one transaction (PostgreSQL), so decks of
 * hundreds of thousands of prefixes import in seconds.
 */
class RateImporter
{
    /** Accepted header names for each column (lower-case, spaces/dashes as _). */
    public const COLUMNS = [
        'prefix' => ['prefix', 'code', 'dial_code', 'dialcode', 'dialing_code', 'destination_code'],
        'destination' => ['destination', 'description', 'name', 'country', 'dest'],
        'rate' => ['rate', 'price', 'rate_per_min', 'rate_per_minute', 'per_minute'],
        'connect_fee' => ['connect_fee', 'connection_fee', 'setup_fee'],
        'initial_increment' => ['initial_increment', 'min_duration', 'first_increment', 'initial'],
        'increment' => ['increment', 'billing_increment', 'next_increment'],
        'effective_from' => ['effective_from', 'effective_date', 'effective', 'valid_from'],
    ];

    public const MAX_ERRORS = 50;

    public const NOTICE_DAYS = 7;

    public function run(RateImport $import): void
    {
        $import->update(['status' => 'running', 'started_at' => now(), 'errors' => null, 'stats' => null]);

        try {
            [$rows, $errors] = $this->parse(Storage::disk('local')->path($import->path), CarbonImmutable::parse($import->effective_from));

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
     * @return array{0: list<array<string, mixed>>, 1: list<string>}
     */
    public function parse(string $file, CarbonImmutable $defaultEffective): array
    {
        $handle = @fopen($file, 'r');
        if ($handle === false) {
            throw new RuntimeException('Could not open the uploaded file.');
        }

        try {
            $first = (string) fgets($handle);
            $first = preg_replace('/^\xEF\xBB\xBF/', '', $first); // UTF-8 BOM
            $delimiter = $this->detectDelimiter($first);
            $map = $this->mapHeader(str_getcsv($first, $delimiter, '"', ''));

            $rows = [];
            $errors = [];
            $seen = [];
            $line = 1;
            while (($cells = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
                $line++;
                if ($cells === [null] || implode('', $cells) === '') {
                    continue; // blank line
                }
                $row = $this->row($cells, $map, $defaultEffective, $error);
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

    /** @return array<string, int> column => index */
    private function mapHeader(array $header): array
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
        foreach (['prefix', 'rate'] as $required) {
            if (! isset($map[$required])) {
                throw new RuntimeException("The header row needs a \"{$required}\" column (found: ".implode(', ', array_filter($header)).').');
            }
        }

        return $map;
    }

    private function row(array $cells, array $map, CarbonImmutable $defaultEffective, ?string &$error): array
    {
        $error = null;
        $get = fn (string $column) => isset($map[$column]) ? trim((string) ($cells[$map[$column]] ?? '')) : '';

        $prefix = preg_replace('/[\s+\-]/', '', $get('prefix'));
        if (! preg_match('/^[0-9]{1,32}$/', $prefix)) {
            $error = "prefix \"{$get('prefix')}\" must be 1-32 digits.";

            return [];
        }

        $numbers = [];
        foreach (['rate' => null, 'connect_fee' => '0'] as $column => $default) {
            $value = $get($column);
            if ($value === '' && $default !== null) {
                $value = $default;
            }
            if (! is_numeric($value) || (float) $value < 0 || (float) $value > 999999) {
                $error = "{$column} \"{$value}\" must be a number of 0 or more.";

                return [];
            }
            $numbers[$column] = $value;
        }

        $increments = [];
        foreach (['initial_increment' => 60, 'increment' => 60] as $column => $default) {
            $value = $get($column) === '' ? (string) $default : $get($column);
            if (! ctype_digit($value) || (int) $value < 1 || (int) $value > 3600) {
                $error = "{$column} \"{$value}\" must be a whole number of seconds (1-3600).";

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

    /** @return array<string, int> */
    private function apply(RateImport $import, array $rows): array
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
            if ($import->mode === 'replace') {
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
            if ($import->mode === 'replace') {
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
