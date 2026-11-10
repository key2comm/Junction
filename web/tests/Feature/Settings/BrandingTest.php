<?php

namespace Tests\Feature\Settings;

use App\Models\Account;
use App\Models\User;
use App\Support\Branding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_logo_icon_and_company_name_can_be_set_and_removed(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create();

        $this->actingAs($admin)->get(route('branding.edit'))->assertInertia(fn (Assert $p) => $p->component('settings/Branding')
            ->where('settings', ['logo' => null, 'icon' => null, 'company' => null])->where('branding.company', null));

        $this->actingAs($admin)->post(route('branding.update'), ['logo' => UploadedFile::fake()->create('logo.exe', 10)])->assertSessionHasErrors('logo');
        $this->actingAs($admin)->post(route('branding.update'), [
            'company_name' => '  Key2 Comm Inc. ',
            'logo' => UploadedFile::fake()->createWithContent('logo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=')),
            'icon' => UploadedFile::fake()->createWithContent('icon.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"/>'),
        ])->assertSessionHasNoErrors()->assertRedirect(route('branding.edit'));

        $this->assertSame('Key2 Comm Inc.', Branding::companyName());
        $this->assertStringContainsString('/branding/logo?v=', Branding::url('logo'));
        // Shared with every page (the footer and logo), and served to anyone (the sign-in page shows it).
        $this->actingAs($admin)->get(route('branding.edit'))->assertInertia(fn (Assert $p) => $p->where('branding.company', 'Key2 Comm Inc.'));
        auth()->logout();
        $this->get('/branding/logo')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get('/branding/icon')->assertOk()->assertHeader('Content-Type', 'image/svg+xml')->assertHeader('Content-Security-Policy');
        $this->get('/login')->assertOk()->assertSee(Branding::url('icon'), false); // the browser tab icon

        $this->actingAs($admin)->post(route('branding.update'), ['company_name' => '', 'remove_logo' => '1'])->assertSessionHasNoErrors();
        $this->assertNull(Branding::companyName());
        $this->assertNull(Branding::url('logo'));
        $this->assertNotNull(Branding::url('icon')); // not ticked: kept
        $this->get('/branding/logo')->assertNotFound();
        $this->assertCount(1, Storage::disk('local')->files('branding'));
    }

    public function test_only_admins_change_branding(): void
    {
        $customer = Account::factory()->create(['is_customer' => true]);
        $portal = User::factory()->create(['account_id' => $customer->id]);

        $this->actingAs($portal)->post(route('branding.update'), ['company_name' => 'Hijacked'])->assertForbidden();
        $this->assertNull(Branding::companyName());
    }
}
