<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Settings edited in the admin UI, stored in app_settings and cached.
 * Values for keys listed in SECRET are encrypted with the app key.
 */
final class Settings
{
    public const SECRET = ['mail.password'];

    private const CACHE = 'softswitch.settings';

    /** @return array<string, mixed> */
    public static function all(): array
    {
        try {
            return Cache::rememberForever(self::CACHE, fn () => DB::table('app_settings')->pluck('value', 'key')
                ->map(fn ($v) => json_decode((string) $v, true))->all());
        } catch (Throwable) {
            return []; // e.g. during install, before migrations
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::all()[$key] ?? null;
        if ($value === null || $value === '') {
            return $default;
        }
        if (in_array($key, self::SECRET, true)) {
            try {
                return Crypt::decryptString($value);
            } catch (Throwable) {
                return $default;
            }
        }

        return $value;
    }

    /** @param  array<string, mixed>  $values */
    public static function put(array $values): void
    {
        foreach ($values as $key => $value) {
            if ($value !== null && in_array($key, self::SECRET, true)) {
                $value = Crypt::encryptString((string) $value);
            }
            DB::table('app_settings')->updateOrInsert(
                ['key' => $key],
                ['value' => json_encode($value), 'updated_at' => now(), 'created_at' => now()],
            );
        }
        Cache::forget(self::CACHE);
    }
}
