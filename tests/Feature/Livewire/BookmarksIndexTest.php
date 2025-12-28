<?php

namespace Tests\Feature\Livewire;

use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class BookmarksIndexTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private IntegrationGroup $group;

    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->group = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'fetch',
        ]);
        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'fetch',
        ]);
    }

    /**
     * @test
     */
    public function bookmarks_page_loads_successfully(): void
    {
        $response = $this->actingAs($this->user)->get('/bookmarks');

        $response->assertStatus(200);
        $response->assertSeeLivewire('bookmarks.index');
    }

    /**
     * @test
     */
    public function old_fetch_route_redirects_to_new_bookmarks_route(): void
    {
        $response = $this->actingAs($this->user)->get('/bookmarks/fetch');

        $response->assertRedirect('/bookmarks?tab=urls');
    }

    /**
     * @test
     */
    public function default_tab_is_all_bookmarks(): void
    {
        $this->actingAs($this->user);

        Volt::test('bookmarks.index')
            ->assertSet('activeTab', 'all');
    }

    /**
     * @test
     */
    public function tab_parameter_sets_active_tab(): void
    {
        $this->actingAs($this->user);

        // Test default tab without query parameter
        $response = $this->get('/bookmarks');
        $response->assertStatus(200);

        // Test with tab query parameter
        $response = $this->get('/bookmarks?tab=saved');
        $response->assertStatus(200);

        // Test setting tab via Livewire property
        Volt::test('bookmarks.index')
            ->set('activeTab', 'saved')
            ->assertSet('activeTab', 'saved');
    }

    /**
     * @test
     */
    public function all_tabs_are_accessible(): void
    {
        $tabs = ['all', 'saved', 'urls', 'cookies', 'discovery', 'stats', 'playwright', 'api'];

        foreach ($tabs as $tab) {
            $response = $this->actingAs($this->user)->get("/bookmarks?tab={$tab}");
            $response->assertStatus(200);
        }
    }

    /**
     * @test
     */
    public function saved_pages_shows_only_once_mode_bookmarks(): void
    {
        $this->actingAs($this->user);

        // Create a one-time bookmark
        $savedPage = EventObject::factory()->create([
            'user_id' => $this->user->id,
            'concept' => 'bookmark',
            'type' => 'fetch_webpage',
            'metadata' => ['fetch_mode' => 'once', 'domain' => 'example.com'],
        ]);

        // Create a recurring bookmark
        $recurringPage = EventObject::factory()->create([
            'user_id' => $this->user->id,
            'concept' => 'bookmark',
            'type' => 'fetch_webpage',
            'metadata' => ['fetch_mode' => 'recurring', 'domain' => 'test.com'],
        ]);

        $component = Volt::test('bookmarks.index')
            ->set('activeTab', 'saved');

        $savedPages = $component->get('savedPages');

        $this->assertCount(1, $savedPages);
        $this->assertEquals($savedPage->id, $savedPages[0]['id']);
    }

    /**
     * @test
     */
    public function saved_pages_filters_by_search(): void
    {
        $this->actingAs($this->user);

        EventObject::factory()->create([
            'user_id' => $this->user->id,
            'concept' => 'bookmark',
            'type' => 'fetch_webpage',
            'title' => 'Laravel Documentation',
            'url' => 'https://laravel.com/docs',
            'metadata' => ['fetch_mode' => 'once', 'domain' => 'laravel.com'],
        ]);

        EventObject::factory()->create([
            'user_id' => $this->user->id,
            'concept' => 'bookmark',
            'type' => 'fetch_webpage',
            'title' => 'PHP Manual',
            'url' => 'https://php.net/manual',
            'metadata' => ['fetch_mode' => 'once', 'domain' => 'php.net'],
        ]);

        $component = Volt::test('bookmarks.index')
            ->set('savedSearch', 'Laravel');

        $savedPages = $component->get('savedPages');

        $this->assertCount(1, $savedPages);
        $this->assertEquals('Laravel Documentation', $savedPages[0]['title']);
    }

    /**
     * @test
     */
    public function saved_pages_filters_by_domain(): void
    {
        $this->actingAs($this->user);

        EventObject::factory()->create([
            'user_id' => $this->user->id,
            'concept' => 'bookmark',
            'type' => 'fetch_webpage',
            'metadata' => ['fetch_mode' => 'once', 'domain' => 'laravel.com'],
        ]);

        EventObject::factory()->create([
            'user_id' => $this->user->id,
            'concept' => 'bookmark',
            'type' => 'fetch_webpage',
            'metadata' => ['fetch_mode' => 'once', 'domain' => 'php.net'],
        ]);

        $component = Volt::test('bookmarks.index')
            ->set('savedDomainFilter', 'laravel.com');

        $savedPages = $component->get('savedPages');

        $this->assertCount(1, $savedPages);
        $this->assertEquals('laravel.com', $savedPages[0]['domain']);
    }

    /**
     * @test
     */
    public function saved_pages_filters_by_status(): void
    {
        $this->actingAs($this->user);

        EventObject::factory()->create([
            'user_id' => $this->user->id,
            'concept' => 'bookmark',
            'type' => 'fetch_webpage',
            'metadata' => [
                'fetch_mode' => 'once',
                'domain' => 'success.com',
                'last_error' => null,
                'last_checked_at' => now()->subHour()->toIso8601String(),
            ],
        ]);

        EventObject::factory()->create([
            'user_id' => $this->user->id,
            'concept' => 'bookmark',
            'type' => 'fetch_webpage',
            'metadata' => [
                'fetch_mode' => 'once',
                'domain' => 'error.com',
                'last_error' => 'Failed to fetch',
            ],
        ]);

        // Test filtering by errors
        $component = Volt::test('bookmarks.index')
            ->set('savedStatusFilter', 'errors');

        $savedPages = $component->get('savedPages');

        $this->assertCount(1, $savedPages);
        $this->assertEquals('error.com', $savedPages[0]['domain']);

        // Test filtering by success
        $component = Volt::test('bookmarks.index')
            ->set('savedStatusFilter', 'success');

        $savedPages = $component->get('savedPages');

        $this->assertCount(1, $savedPages);
        $this->assertEquals('success.com', $savedPages[0]['domain']);
    }

    /**
     * @test
     */
    public function saved_pages_sorts_correctly(): void
    {
        $this->actingAs($this->user);

        EventObject::factory()->create([
            'user_id' => $this->user->id,
            'concept' => 'bookmark',
            'type' => 'fetch_webpage',
            'title' => 'Old Page',
            'metadata' => [
                'fetch_mode' => 'once',
                'domain' => 'old.com',
                'last_checked_at' => now()->subDays(10)->toIso8601String(),
            ],
        ]);

        EventObject::factory()->create([
            'user_id' => $this->user->id,
            'concept' => 'bookmark',
            'type' => 'fetch_webpage',
            'title' => 'New Page',
            'metadata' => [
                'fetch_mode' => 'once',
                'domain' => 'new.com',
                'last_checked_at' => now()->toIso8601String(),
            ],
        ]);

        // Test sort by last_changed (default)
        $component = Volt::test('bookmarks.index')
            ->set('savedSortBy', 'last_changed');

        $savedPages = $component->get('savedPages');

        $this->assertEquals('New Page', $savedPages[0]['title']);
        $this->assertEquals('Old Page', $savedPages[1]['title']);

        // Test sort by title
        $component = Volt::test('bookmarks.index')
            ->set('savedSortBy', 'title');

        $savedPages = $component->get('savedPages');

        $this->assertEquals('New Page', $savedPages[0]['title']);
        $this->assertEquals('Old Page', $savedPages[1]['title']);
    }

    /**
     * @test
     */
    public function clear_saved_filters_resets_all_filters(): void
    {
        $this->actingAs($this->user);

        Volt::test('bookmarks.index')
            ->set('savedSearch', 'test')
            ->set('savedDomainFilter', 'example.com')
            ->set('savedStatusFilter', 'errors')
            ->set('savedSortBy', 'title')
            ->call('clearSavedFilters')
            ->assertSet('savedSearch', '')
            ->assertSet('savedDomainFilter', '')
            ->assertSet('savedStatusFilter', 'all')
            ->assertSet('savedSortBy', 'last_changed');
    }

    /**
     * @test
     */
    public function delete_saved_removes_bookmark(): void
    {
        $this->actingAs($this->user);

        $page = EventObject::factory()->create([
            'user_id' => $this->user->id,
            'concept' => 'bookmark',
            'type' => 'fetch_webpage',
            'metadata' => ['fetch_mode' => 'once'],
        ]);

        Volt::test('bookmarks.index')
            ->call('deleteSaved', $page->id)
            ->assertHasNoErrors();

        $this->assertModelMissing($page);
    }

    /**
     * @test
     */
    public function delete_saved_only_allows_deleting_own_bookmarks(): void
    {
        $this->actingAs($this->user);

        $otherUser = User::factory()->create();
        $otherPage = EventObject::factory()->create([
            'user_id' => $otherUser->id,
            'concept' => 'bookmark',
            'type' => 'fetch_webpage',
            'metadata' => ['fetch_mode' => 'once'],
        ]);

        Volt::test('bookmarks.index')
            ->call('deleteSaved', $otherPage->id)
            ->assertForbidden();
    }

    /**
     * @test
     */
    public function saved_domain_options_returns_unique_domains(): void
    {
        $this->actingAs($this->user);

        EventObject::factory()->create([
            'user_id' => $this->user->id,
            'concept' => 'bookmark',
            'type' => 'fetch_webpage',
            'metadata' => ['fetch_mode' => 'once', 'domain' => 'laravel.com'],
        ]);

        EventObject::factory()->create([
            'user_id' => $this->user->id,
            'concept' => 'bookmark',
            'type' => 'fetch_webpage',
            'metadata' => ['fetch_mode' => 'once', 'domain' => 'laravel.com'],
        ]);

        EventObject::factory()->create([
            'user_id' => $this->user->id,
            'concept' => 'bookmark',
            'type' => 'fetch_webpage',
            'metadata' => ['fetch_mode' => 'once', 'domain' => 'php.net'],
        ]);

        $response = $this->get('/bookmarks?tab=saved');

        $response->assertStatus(200);
        $response->assertSee('laravel.com');
        $response->assertSee('php.net');
    }

    /**
     * @test
     */
    public function component_requires_authentication(): void
    {
        $response = $this->get('/bookmarks');

        $response->assertRedirect('/login');
    }
}
