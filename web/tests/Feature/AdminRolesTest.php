<?php

namespace Tests\Feature;

use App\Mail\AdminInviteMail;
use App\Models\Account;
use App\Models\AdminInvitation;
use App\Models\User;
use App\Support\AdminAreas;
use App\Support\AdminRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Settings → Admin users: access levels and invitations. */
class AdminRolesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(AdminRole $role): User
    {
        return User::factory()->create(['role' => $role->value]);
    }

    private function confirmed(): array
    {
        return ['auth.password_confirmed_at' => time()];
    }

    public function test_every_admin_route_belongs_to_an_area(): void
    {
        $unlisted = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => in_array('admin', $r->gatherMiddleware(), true))
            ->reject(fn ($r) => AdminAreas::listed($r->getName()))
            ->map(fn ($r) => $r->getName() ?? $r->uri())->values()->all();

        $this->assertSame([], $unlisted, 'Add these routes to App\Support\AdminAreas');
    }

    public function test_operations_runs_the_switch_but_not_billing_or_settings(): void
    {
        $ops = $this->admin(AdminRole::Operations);
        $this->actingAs($ops)->get(route('carriers.index'))->assertOk();
        $this->actingAs($ops)->get(route('live.index'))->assertOk();
        $this->actingAs($ops)->get(route('dashboard'))->assertInertia(fn (Assert $p) => $p
            ->where('access.role', 'operations')->where('access.areas.billing', null)->where('access.areas.routing', 'write'));

        $this->actingAs($ops)->get(route('invoices.index'))->assertRedirect(route('dashboard'));
        $this->actingAs($ops)->get(route('certificate.edit'))->assertRedirect(route('dashboard'));
        $this->actingAs($ops)->get(route('admin-users.index'))->assertRedirect(route('dashboard'));
    }

    public function test_billing_sees_routing_but_cannot_change_it(): void
    {
        $billing = $this->admin(AdminRole::Billing);
        $this->actingAs($billing)->get(route('carriers.index'))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('access.page', 'read'));
        $this->actingAs($billing)->get(route('billing.edit'))->assertOk();

        $this->actingAs($billing)->from(route('routing-plans.index'))
            ->post(route('routing-plans.store'), ['name' => 'Nope', 'mode' => 'lcr'])
            ->assertRedirect(route('routing-plans.index'));
        $this->assertDatabaseMissing('routing_plans', ['name' => 'Nope']);
    }

    public function test_read_only_cannot_change_anything(): void
    {
        $viewer = $this->admin(AdminRole::ReadOnly);
        $account = Account::factory()->create();
        $this->actingAs($viewer)->get(route('accounts.show', $account))->assertOk();
        $this->actingAs($viewer)->get(route('invoices.index'))->assertOk();
        $this->actingAs($viewer)->patch(route('accounts.update', $account), ['name' => 'Changed'])->assertRedirect();
        $this->assertNotSame('Changed', $account->fresh()->name);
        $this->actingAs($viewer)->get(route('firewall.index'))->assertRedirect(route('dashboard'));
    }

    public function test_only_owners_manage_admins(): void
    {
        $this->actingAs($this->admin(AdminRole::Admin))->get(route('certificate.edit'))->assertOk();
        $this->actingAs($this->admin(AdminRole::Admin))->get(route('admin-users.index'))->assertRedirect(route('dashboard'));
        $owner = $this->admin(AdminRole::Owner);
        // Someone signed in somewhere: their last activity is shown.
        DB::table('sessions')->insert(['id' => 'abc', 'user_id' => $owner->id, 'payload' => '', 'last_activity' => now()->getTimestamp()]);
        $this->actingAs($owner)->get(route('admin-users.index'))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('settings/AdminUsers')->has('roles', 5)
                ->where('users', fn ($users) => collect($users)->firstWhere('me', true)['last_seen'] !== null));
    }

    public function test_an_invitation_is_emailed_and_creates_the_admin_when_accepted(): void
    {
        Mail::fake();
        config(['mail.default' => 'array']);
        $owner = $this->admin(AdminRole::Owner);

        $this->actingAs($owner)->withSession($this->confirmed())
            ->post(route('admin-invitations.store'), ['email' => ' New.Person@Example.com ', 'role' => 'billing'])
            ->assertRedirect(route('admin-users.index'));
        $invitation = AdminInvitation::query()->sole();
        $this->assertSame(['new.person@example.com', AdminRole::Billing], [$invitation->email, $invitation->role]);

        // A fresh link (as the email or the copy-the-link box would carry).
        $token = $invitation->issueToken();
        auth()->logout();
        $this->get(route('invitation.show', $token))->assertInertia(fn (Assert $p) => $p
            ->component('auth/AcceptInvite')->where('email', 'new.person@example.com')->where('role', 'Billing'));

        $this->post(route('invitation.accept', $token), ['name' => 'New Person', 'password' => 'Str0ng-Passw0rd!x', 'password_confirmation' => 'Str0ng-Passw0rd!x'])
            ->assertRedirect(route('dashboard'));
        $user = User::query()->where('email', 'new.person@example.com')->sole();
        $this->assertSame(AdminRole::Billing, $user->role);
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseCount('admin_invitations', 0);

        // The link works once.
        auth()->logout();
        $this->get(route('invitation.show', $token))->assertRedirect(route('login'));
    }

    public function test_the_mail_carries_the_link(): void
    {
        $invitation = new AdminInvitation(['email' => 'x@example.com', 'role' => 'operations']);
        $invitation->issueToken();
        $mail = new AdminInviteMail($invitation, 'https://switch.test/invite/abc', 'Pat');
        $mail->assertSeeInHtml('https://switch.test/invite/abc', false);
        $mail->assertSeeInText('Operations (NOC)');
    }

    public function test_expired_or_existing_emails_are_refused(): void
    {
        $owner = $this->admin(AdminRole::Owner);
        $this->actingAs($owner)->withSession($this->confirmed())
            ->post(route('admin-invitations.store'), ['email' => $owner->email, 'role' => 'admin'])
            ->assertSessionHasErrors('email');

        $invitation = new AdminInvitation(['email' => 'late@example.com', 'role' => 'admin']);
        $token = $invitation->issueToken();
        $this->travel(AdminInvitation::DAYS + 1)->days();
        auth()->logout();
        $this->get(route('invitation.show', $token))->assertRedirect(route('login'));
    }

    public function test_owners_change_levels_and_remove_admins_but_not_themselves(): void
    {
        $owner = $this->admin(AdminRole::Owner);
        $other = $this->admin(AdminRole::Admin);

        $this->actingAs($owner)->withSession($this->confirmed())->patch(route('admin-users.update', $other), ['role' => 'readonly'])->assertRedirect();
        $this->assertSame(AdminRole::ReadOnly, $other->fresh()->role);

        $this->actingAs($owner)->withSession($this->confirmed())->patch(route('admin-users.update', $owner), ['role' => 'readonly'])->assertRedirect();
        $this->assertSame(AdminRole::Owner, $owner->fresh()->role);

        $this->actingAs($owner)->withSession($this->confirmed())->delete(route('admin-users.destroy', $owner))->assertRedirect();
        $this->assertModelExists($owner);
        $this->actingAs($owner)->withSession($this->confirmed())->delete(route('admin-users.destroy', $other))->assertRedirect();
        $this->assertModelMissing($other);
    }

    public function test_the_server_command_makes_owners(): void
    {
        $this->artisan('softswitch:admin', ['email' => 'root@example.com', '--password' => 'a-long-password-1'])->assertSuccessful();
        $this->assertSame(AdminRole::Owner, User::query()->where('email', 'root@example.com')->sole()->role);
    }
}
