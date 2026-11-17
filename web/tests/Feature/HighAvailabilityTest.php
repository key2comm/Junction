<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\User;
use App\Services\AlertEngine;
use App\Services\HighAvailability;
use App\Support\AdminRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** High availability: the main server's view of its backup, and the backup server's page. */
class HighAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        HighAvailability::fakeStandby(null);
        File::deleteDirectory(HighAvailability::stateDir());
        parent::tearDown();
    }

    private function asBackup(): void
    {
        config(['softswitch.ha.role' => 'standby', 'softswitch.ha.peer' => '10.0.0.1']);
        HighAvailability::fakeStandby(true);
    }

    public function test_a_single_server_is_unaffected(): void
    {
        $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('ha', null));
        $this->assertFalse(HighAvailability::isStandby());
    }

    public function test_the_main_server_alerts_when_its_backup_is_not_connected(): void
    {
        config(['softswitch.ha.role' => 'primary', 'softswitch.ha.peer' => '10.0.0.2']);
        $this->actingAs(User::factory()->create())->get(route('dashboard'))
            ->assertInertia(fn (Assert $p) => $p->where('ha.peer', '10.0.0.2')->where('ha.connected', false));

        app(AlertEngine::class)->run();
        $alert = Alert::query()->where('type', 'ha_backup')->sole();
        $this->assertSame('open', $alert->status);
        $this->assertStringContainsString('10.0.0.2', $alert->title);

        // Without high availability the alert resolves itself.
        config(['softswitch.ha.role' => null]);
        app(AlertEngine::class)->run();
        $this->assertSame('resolved', $alert->fresh()->status);
    }

    public function test_the_heartbeat_shows_how_old_the_backups_data_is(): void
    {
        HighAvailability::beat();
        HighAvailability::beat();
        $main = HighAvailability::main();
        $this->assertLessThan(5, $main['behind']);
        $this->assertFalse($main['streaming']); // (no replication in tests)
        $this->assertFalse($main['healthy']);
    }

    public function test_the_backup_only_shows_its_own_page(): void
    {
        $this->asBackup();
        $owner = User::factory()->create();

        $this->actingAs($owner)->get(route('dashboard'))->assertRedirect(route('ha.standby'));
        $this->actingAs($owner)->get(route('carriers.index'))->assertRedirect(route('ha.standby'));
        $this->actingAs($owner)->get(route('ha.standby'))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('ha/Standby')->where('canPromote', true)->where('main.main', '10.0.0.1')->where('main.healthy', false));
        $this->getJson('/api/v1/cdrs/summary')->assertStatus(503);
        auth()->logout();
        $this->get(route('login'))->assertOk(); // signing in still works
    }

    public function test_an_admin_asks_for_the_backup_to_become_the_main_server(): void
    {
        $this->asBackup();
        $viewer = User::factory()->create(['role' => AdminRole::ReadOnly->value]);
        $this->actingAs($viewer)->get(route('ha.standby'))->assertInertia(fn (Assert $p) => $p->where('canPromote', false));
        $this->actingAs($viewer)->withSession(['auth.password_confirmed_at' => time()])->post(route('ha.promote'));
        $this->assertFalse(HighAvailability::promotionRequested());

        $owner = User::factory()->create();
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])->post(route('ha.promote'))
            ->assertRedirect(route('ha.standby'));
        $this->assertTrue(HighAvailability::promotionRequested());
        $this->actingAs($owner)->get(route('ha.standby'))->assertInertia(fn (Assert $p) => $p->where('promotion.status', 'requested'));
    }

    public function test_the_backup_watch_notes_when_replication_breaks(): void
    {
        $this->asBackup();
        $this->artisan('softswitch:ha-watch')->expectsOutput('Not in sync')->assertSuccessful();
        $this->artisan('softswitch:ha-watch')->assertSuccessful();
        $state = json_decode(File::get(HighAvailability::stateDir().'/watch.json'), true);
        $this->assertSame([false, 2], [$state['healthy'], $state['bad']]);
    }
}
