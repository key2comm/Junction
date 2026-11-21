<?php

namespace Tests\Feature\Settings;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\PbxDid;
use App\Models\User;
use App\Support\AdminRole;
use App\Support\Audit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Settings → Audit log. */
class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['name' => 'Olive Owner']);
    }

    public function test_a_change_records_who_and_the_old_and_new_values(): void
    {
        $customer = Account::factory()->create(['is_customer' => true, 'is_vendor' => false]);
        $carrier = Account::factory()->vendor()->create();
        $did = PbxDid::create(['number' => '14165550100', 'provider_account_id' => $carrier->id, 'language' => 'en', 'status' => 'free']);

        $this->actingAs($this->owner)->put(route('dids.update', $did), ['number' => '14165550100', 'provider_account_id' => $carrier->id, 'language' => 'en',
            'status' => 'active', 'account_id' => $customer->id, 'destination' => ['type' => 'hangup'], 'description' => 'Main line'])
            ->assertSessionHasNoErrors();

        $log = AuditLog::query()->sole();
        $this->assertSame($this->owner->id, $log->user_id);
        $this->assertSame('Olive Owner', $log->user_name);
        $this->assertSame('web', $log->via);
        $this->assertSame('dids.update', $log->action);
        $this->assertSame(['DID', $did->id, '14165550100'], [$log->subject_type, $log->subject_id, $log->subject_label]);
        $fields = $log->changes[0]['fields'];
        $this->assertSame(['free', 'active'], $fields['status']);
        $this->assertSame([null, 'Main line'], $fields['description']);
        $this->assertSame('Changed DID 14165550100', $log->summary());
    }

    public function test_secrets_are_never_stored(): void
    {
        $this->actingAs($this->owner)->put(route('user-password.update'), [
            'current_password' => 'password', 'password' => 'A-new-pass-2026!', 'password_confirmation' => 'A-new-pass-2026!',
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()])->put(route('backups.update'), [
            'enabled' => '1', 'time' => '02:00', 'timezone' => 'UTC', 'retention_days' => 7, 'sftp_keep_days' => 0, 's3_keep_days' => 0,
            's3_provider' => 'aws', 'password' => 'my backup password 123',
        ])->assertSessionHasNoErrors();

        $all = AuditLog::query()->get()->toJson();
        $this->assertStringNotContainsString('A-new-pass-2026!', $all);
        $this->assertStringNotContainsString('my backup password 123', $all);
        $user = AuditLog::query()->where('action', 'user-password.update')->sole();
        $this->assertSame(['••••', '••••'], $user->changes[0]['fields']['password']);
        $settings = AuditLog::query()->where('action', 'backups.update')->sole();
        $setting = collect($settings->changes)->firstWhere('type', 'Setting');
        $this->assertSame([null, '••••'], $setting['fields']['backup.password']);
    }

    public function test_refused_and_read_only_requests_are_not_logged(): void
    {
        $this->actingAs($this->owner)->post(route('dids.store'), ['number' => ''])->assertSessionHasErrors();
        $this->actingAs($this->owner)->get(route('dids.index'))->assertOk();
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_sign_ins_and_failed_sign_ins_are_logged(): void
    {
        $this->post(route('login.store'), ['email' => $this->owner->email, 'password' => 'wrong']);
        $this->post(route('login.store'), ['email' => $this->owner->email, 'password' => 'password']);

        $this->assertSame(['login_failed', 'login'], AuditLog::query()->orderBy('id')->pluck('event')->all());
        $this->assertSame($this->owner->email, AuditLog::query()->where('event', 'login_failed')->value('user_email'));
    }

    public function test_owners_and_admins_see_it_and_only_owners_set_retention(): void
    {
        $admin = User::factory()->create(['role' => AdminRole::Admin->value]);
        foreach ([AdminRole::Operations, AdminRole::Billing, AdminRole::ReadOnly] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role->value]))->get(route('audit.index'))->assertRedirect(route('dashboard'));
        }
        $this->actingAs($admin)->get(route('audit.index'))->assertOk()->assertInertia(fn (Assert $p) => $p->component('settings/AuditLog')
            ->where('access.areas.audit', 'read'));
        $this->actingAs($admin)->put(route('audit.update'), ['retention_days' => 730]);
        $this->assertSame(Audit::DEFAULT_RETENTION, Audit::retentionDays());

        $this->actingAs($this->owner)->put(route('audit.update'), ['retention_days' => 730])->assertRedirect(route('audit.index'));
        $this->assertSame(730, Audit::retentionDays());
    }

    public function test_the_list_filters_and_old_entries_are_pruned(): void
    {
        AuditLog::query()->create(['user_id' => $this->owner->id, 'user_name' => 'Olive Owner', 'via' => 'web', 'event' => 'change', 'action' => 'dids.update',
            'subject_type' => 'DID', 'subject_label' => '14165550100', 'created_at' => now()]);
        AuditLog::query()->create(['via' => 'web', 'event' => 'login_failed', 'user_email' => 'x@example.com', 'created_at' => now()->subDays(400)]);

        $this->actingAs($this->owner)->get(route('audit.index', ['q' => '5550100']))->assertInertia(fn (Assert $p) => $p
            ->has('logs.data', 1)->where('logs.data.0.summary', 'Change · Dids'));
        $this->actingAs($this->owner)->get(route('audit.index', ['event' => 'login_failed']))->assertInertia(fn (Assert $p) => $p->has('logs.data', 1));

        $this->assertSame(1, Audit::prune());
        $this->assertSame(1, AuditLog::query()->count());
    }
}
