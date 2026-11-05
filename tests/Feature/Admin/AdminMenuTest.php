<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Support\AdminPages;
use App\Support\AdminTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IsolatesLogs;
use Tests\TestCase;

/**
 * Admin audit PR 5: one page list for the menu and Spotlight, GoCardless behind God Mode.
 */
class AdminMenuTest extends TestCase
{
    use IsolatesLogs;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);
        config(['spark.admin.global_operators' => [(string) $this->admin->id]]);
        $this->actingAs($this->admin);
    }

    #[Test]
    public function every_listed_page_has_a_working_route(): void
    {
        foreach (AdminPages::all() as $page) {
            $this->assertNotEmpty(route($page['route']), $page['title']);
        }
    }

    #[Test]
    public function the_menu_lists_every_visible_page_and_no_removed_ones(): void
    {
        $response = $this->get(route('admin.pulse.index'))->assertOk();

        foreach (AdminPages::visible() as $page) {
            $response->assertSee(route($page['route']), false);
        }

        $response->assertSee(route('admin.push-notifications.index'), false)
            ->assertDontSee('Pending Links')
            ->assertDontSee('Block View')
            ->assertDontSee(route('admin.gocardless.index'), false);
    }

    #[Test]
    public function gocardless_is_only_listed_and_reachable_in_god_mode(): void
    {
        $titles = fn () => array_column(AdminPages::visible(), 'title');

        $this->assertNotContains('GoCardless', $titles());
        $this->get(route('admin.gocardless.index'))->assertRedirect(route('admin.god-mode.index'));
        $this->delete(route('admin.gocardless.deleteAgreement', ['agreementId' => 'x']))->assertRedirect(route('admin.god-mode.index'));

        $this->enableGodMode();

        $this->assertContains('GoCardless', $titles());
        $this->get(route('admin.gocardless.index'))->assertOk();
    }

    #[Test]
    public function the_pending_links_page_is_gone(): void
    {
        $this->get(route('admin.pending-links.index'))->assertRedirect('/flint');
    }

    private function enableGodMode(): void
    {
        $this->withSession([AdminTenant::SESSION_KEY => [
            'started_at' => now()->toJSON(),
            'expires_at' => now()->addMinutes(30)->toJSON(),
        ]]);
    }
}
