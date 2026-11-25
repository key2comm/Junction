<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL functions kept as one SQL file each in database/sql/, rather than
 * redefined migration by migration. They are applied after every
 * `php artisan migrate` (AppServiceProvider listens for it), so a server always
 * runs the version in its code, and a function may use anything the migrations
 * create. Each file uses CREATE OR REPLACE, so applying it again changes nothing.
 */
class DatabaseFunctions
{
    /** @return list<string> the files applied */
    public static function apply(): array
    {
        if (DB::getDriverName() !== 'pgsql') {
            return [];
        }
        $files = glob(database_path('sql/*.sql')) ?: [];
        sort($files);
        foreach ($files as $file) {
            DB::unprepared((string) file_get_contents($file));
        }

        return array_map('basename', $files);
    }
}
