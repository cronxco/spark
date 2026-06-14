<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BinAdminTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function admin_bin_page_loads_for_admin_user()
    {
        $response = $this->actingAs($this->admin())
            ->get(route('admin.bin.index'));

        $response->assertSuccessful();
    }

    #[Test]
    public function admin_bin_page_is_forbidden_for_non_admin()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('admin.bin.index'))
            ->assertForbidden();
    }

    #[Test]
    public function admin_bin_page_requires_authentication()
    {
        $response = $this->get(route('admin.bin.index'));

        $response->assertRedirect(route('login'));
    }

    #[Test]
    public function admin_bin_delete_endpoint_works_for_admin()
    {
        $response = $this->actingAs($this->admin())
            ->post(route('admin.bin.delete'));

        $response->assertSuccessful();
        $response->assertJson([
            'message' => 'Deletion process started. All items will be permanently deleted.',
        ]);
    }

    #[Test]
    public function admin_bin_delete_endpoint_is_forbidden_for_non_admin()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('admin.bin.delete'))
            ->assertForbidden();
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->is_admin = true;
        $admin->save();

        return $admin;
    }
}
