<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_root_sends_people_to_the_right_place()
    {
        $this->get(route('home'))->assertRedirect(route('login'));
        $this->get(route('login'))->assertOk();

        $this->actingAs(User::factory()->create())->get(route('home'))->assertRedirect(route('dashboard'));
        $this->actingAs(User::factory()->create(['account_id' => Account::factory()->create()->id]))
            ->get(route('home'))->assertRedirect(route('dashboard'));
        $this->get(route('dashboard'))->assertRedirect(route('portal.home'));
    }
}
