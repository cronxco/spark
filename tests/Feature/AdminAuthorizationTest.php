<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function guests_are_redirected_from_admin_routes(): void
    {
        $this->get(route('admin.events.index'))->assertRedirect();
    }

    #[Test]
    public function non_admin_users_are_forbidden_from_admin_routes(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)->get(route('admin.events.index'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.gocardless.index'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.migrations.index'))->assertForbidden();
    }

    #[Test]
    public function non_admin_cannot_delete_gocardless_connections(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->delete(route('admin.gocardless.deleteAgreement', ['agreementId' => 'some-id']))
            ->assertForbidden();
    }

    #[Test]
    public function admin_users_can_reach_admin_routes(): void
    {
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->is_admin = true;
        $admin->save();

        // GoCardless admin index swallows API errors and always renders, so it
        // is a stable check that the admin middleware lets admins through.
        $this->actingAs($admin)->get(route('admin.gocardless.index'))->assertOk();
    }
}
