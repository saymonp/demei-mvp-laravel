<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_visiting_home_is_redirected_to_registration(): void
    {
        $response = $this->get('/');

        $response->assertRedirect(route('register', absolute: false));
    }

    public function test_authenticated_user_visiting_home_is_redirected_to_station_dashboard(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertRedirect(route('station-dashboard', absolute: false));
    }
}
