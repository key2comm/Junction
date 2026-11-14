<?php

namespace Tests\Feature;

use App\Jobs\CheckForUpdates;
use App\Models\User;
use App\Services\UpdateCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Dashboard → App version: this server's release against GitHub's latest. */
class UpdateCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_newest_release_on_github_is_compared_with_this_server(): void
    {
        config(['softswitch.version' => '0.1.0-beta', 'softswitch.updates.repo' => 'acme/junction', 'softswitch.updates.token' => 'tok']);
        Http::fake(['api.github.com/repos/acme/junction/releases*' => Http::response([
            ['tag_name' => 'v0.3.0', 'draft' => true, 'html_url' => 'https://x/draft'],
            ['tag_name' => 'v0.1.0-beta', 'draft' => false, 'html_url' => 'https://x/0.1.0', 'published_at' => '2026-11-11T00:00:00Z'],
            ['tag_name' => 'v0.2.0-beta', 'draft' => false, 'html_url' => 'https://x/0.2.0', 'published_at' => '2026-12-01T00:00:00Z'],
        ])]);

        $result = app(UpdateCheck::class)->check();
        $this->assertSame(['ok', '0.2.0-beta', true, 'https://x/0.2.0'], [$result['status'], $result['latest'], $result['newer'], $result['url']]);
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer tok'));

        $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertInertia(fn (Assert $p) => $p
            ->where('update.latest', '0.2.0-beta')->where('update.newer', true));

        // After upgrading, the same check reads as up to date.
        config(['softswitch.version' => '0.2.0-beta']);
        $this->assertFalse(UpdateCheck::last()['newer']);
    }

    public function test_a_private_repository_without_a_token_says_so(): void
    {
        config(['softswitch.updates.token' => null]);
        Http::fake(['api.github.com/*' => Http::response(['message' => 'Not Found'], 404)]);

        $result = app(UpdateCheck::class)->check();
        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('SOFTSWITCH_UPDATE_TOKEN', $result['error']);
    }

    public function test_check_now_runs_on_the_queue(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create())->post(route('dashboard.check-updates'))->assertRedirect();
        Queue::assertPushed(CheckForUpdates::class);
    }
}
