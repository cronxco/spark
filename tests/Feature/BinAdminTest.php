<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BinAdminTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function admin_bin_page_loads_for_authenticated_user()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get(route('admin.bin.index'));

        $response->assertSuccessful();
    }

    /** @test */
    public function admin_bin_page_requires_authentication()
    {
        $response = $this->get(route('admin.bin.index'));

        $response->assertRedirect(route('login'));
    }

    /** @test */
    public function admin_bin_delete_endpoint_works()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->post(route('admin.bin.delete'));

        $response->assertSuccessful();
        $response->assertJson([
            'message' => 'Deletion process started. All items will be permanently deleted.',
        ]);
    }
}
