<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Dashboard → Server → App version: is a newer release on GitHub?
 *
 * Reads the repository's releases (pre-releases included, drafts skipped)
 * from the scheduler or the queue, since php-fpm may not open outside
 * connections (SELinux). The dashboard only shows the cached result. A private
 * repository needs a token (SOFTSWITCH_UPDATE_TOKEN, read access to contents).
 */
class UpdateCheck
{
    public const CACHE_KEY = 'softswitch.update_check';

    /** @return array{status: string, current: string, latest: ?string, newer: bool, url: ?string, published_at: ?string, checked_at: string, error: ?string} */
    public function check(): array
    {
        $current = (string) config('softswitch.version');
        $repo = (string) config('softswitch.updates.repo');
        $result = ['status' => 'error', 'current' => $current, 'latest' => null, 'newer' => false, 'url' => null, 'published_at' => null,
            'checked_at' => now()->toIso8601String(), 'error' => null, 'repo' => $repo];

        try {
            $request = Http::timeout(10)->acceptJson()->withUserAgent('Junction-update-check')
                ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28']);
            if ($token = config('softswitch.updates.token')) {
                $request = $request->withToken((string) $token);
            }
            $response = $request->get("https://api.github.com/repos/{$repo}/releases", ['per_page' => 20]);

            if ($response->status() === 404) {
                $result['error'] = config('softswitch.updates.token')
                    ? "Repository {$repo} not found with this token."
                    : "Repository {$repo} is private or doesn't exist. Set SOFTSWITCH_UPDATE_TOKEN to check a private repository.";
            } elseif (! $response->successful()) {
                $result['error'] = "GitHub answered HTTP {$response->status()}.";
            } else {
                $latest = collect($response->json())->filter(fn ($r) => is_array($r) && empty($r['draft']) && ! empty($r['tag_name']))
                    ->sort(fn ($a, $b) => version_compare(self::bare($b['tag_name']), self::bare($a['tag_name'])))->first();
                $result['status'] = 'ok';
                if ($latest) {
                    $result['latest'] = self::bare($latest['tag_name']);
                    $result['url'] = $latest['html_url'] ?? null;
                    $result['published_at'] = $latest['published_at'] ?? null;
                    $result['newer'] = version_compare($result['latest'], self::bare($current), '>');
                }
            }
        } catch (Throwable $e) {
            $result['error'] = 'Could not reach GitHub: '.$e->getMessage();
        }

        Cache::forever(self::CACHE_KEY, $result);

        return $result;
    }

    /** @return array<string, mixed>|null the last check (current version always up to date) */
    public static function last(): ?array
    {
        $last = Cache::get(self::CACHE_KEY);
        if (! is_array($last)) {
            return null;
        }
        $current = (string) config('softswitch.version');
        $last['current'] = $current;
        $last['newer'] = ! empty($last['latest']) && version_compare((string) $last['latest'], self::bare($current), '>');

        return $last;
    }

    /** "v0.1.0-beta" → "0.1.0-beta" */
    public static function bare(string $tag): string
    {
        return ltrim(trim($tag), 'vV');
    }
}
