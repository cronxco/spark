<?php

namespace Tests\Feature\Integrations;

use App\Integrations\Monzo\MonzoPlugin;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\Place;
use App\Models\Relationship;
use App\Models\User;
use App\Services\GeocodingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class MonzoLocationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Integration $integration;

    protected MonzoPlugin $plugin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $group = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'monzo',
        ]);

        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $group->id,
            'service' => 'monzo',
        ]);

        $this->plugin = new MonzoPlugin;
    }

    /**
     * @test
     */
    public function online_transaction_skips_location(): void
    {
        $transaction = [
            'id' => 'tx_test123',
            'created' => now()->toIso8601String(),
            'amount' => -1000,
            'currency' => 'GBP',
            'description' => 'Online Purchase',
            'category' => 'shopping',
            'merchant' => [
                'id' => 'merch_123',
                'name' => 'Online Store',
                'online' => true, // Online transaction
                'latitude' => 51.5074,
                'longitude' => -0.1278,
            ],
        ];

        $this->plugin->processTransactionItem($this->integration, $transaction, 'acc_123');

        $event = Event::where('source_id', 'tx_test123')->first();

        $this->assertNotNull($event);
        $this->assertNull($event->location);
        $this->assertNull($event->location_address);
    }

    /**
     * @test
     */
    public function in_person_transaction_with_coordinates(): void
    {
        $transaction = [
            'id' => 'tx_test456',
            'created' => now()->toIso8601String(),
            'amount' => -1500,
            'currency' => 'GBP',
            'description' => 'Coffee Shop',
            'category' => 'eating_out',
            'merchant' => [
                'id' => 'merch_456',
                'name' => 'Starbucks',
                'online' => false,
                'latitude' => 51.5074,
                'longitude' => -0.1278,
                'address' => [
                    'address' => '123 High Street',
                    'city' => 'London',
                    'postcode' => 'SW1A 1AA',
                    'country' => 'GB',
                ],
            ],
        ];

        $this->plugin->processTransactionItem($this->integration, $transaction, 'acc_123');

        $event = Event::where('source_id', 'tx_test456')->first();

        $this->assertNotNull($event);
        $this->assertNotNull($event->location);
        $this->assertEquals(51.5074, $event->latitude);
        $this->assertEquals(-0.1278, $event->longitude);
        $this->assertEquals('monzo_api', $event->location_source);
        $this->assertStringContainsString('123 High Street', $event->location_address);
    }

    /**
     * @test
     */
    public function in_person_transaction_geocodes_address(): void
    {
        // Mock geocoding service
        $geocodingMock = Mockery::mock(GeocodingService::class);
        $geocodingMock->shouldReceive('geocode')
            ->once()
            ->with('456 Main St, Manchester, M1 1AA, GB')
            ->andReturn([
                'latitude' => 53.4808,
                'longitude' => -2.2426,
                'formatted_address' => '456 Main St, Manchester, M1 1AA, United Kingdom',
                'country_code' => 'GB',
                'source' => 'geoapify',
            ]);

        $this->app->instance(GeocodingService::class, $geocodingMock);

        $transaction = [
            'id' => 'tx_test789',
            'created' => now()->toIso8601String(),
            'amount' => -2000,
            'currency' => 'GBP',
            'description' => 'Restaurant',
            'category' => 'eating_out',
            'merchant' => [
                'id' => 'merch_789',
                'name' => 'Pizza Express',
                'online' => false,
                // No lat/lng provided
                'address' => [
                    'address' => '456 Main St',
                    'city' => 'Manchester',
                    'postcode' => 'M1 1AA',
                    'country' => 'GB',
                ],
            ],
        ];

        $this->plugin->processTransactionItem($this->integration, $transaction, 'acc_123');

        $event = Event::where('source_id', 'tx_test789')->first();

        $this->assertNotNull($event);
        $this->assertNotNull($event->location);
        $this->assertEquals(53.4808, $event->latitude);
        $this->assertEquals(-2.2426, $event->longitude);
        $this->assertEquals('geoapify', $event->location_source);
    }

    /**
     * @test
     */
    public function transaction_inherits_location_from_counterparty(): void
    {
        // Create counterparty with location
        $counterparty = EventObject::factory()->create([
            'user_id' => $this->user->id,
            'concept' => 'counterparty',
            'type' => 'monzo_counterparty',
            'title' => 'Local Cafe',
        ]);
        $counterparty->setLocation(51.5074, -0.1278, 'London, UK', 'manual');

        $transaction = [
            'id' => 'tx_test999',
            'created' => now()->toIso8601String(),
            'amount' => -500,
            'currency' => 'GBP',
            'description' => 'Local Cafe',
            'category' => 'eating_out',
            'merchant' => [
                'id' => 'merch_999',
                'name' => 'Local Cafe',
                'online' => false,
            ],
        ];

        $this->plugin->processTransactionItem($this->integration, $transaction, 'acc_123');

        $event = Event::where('source_id', 'tx_test999')->first();

        $this->assertNotNull($event);
        $this->assertNotNull($event->location);
        $this->assertEquals(51.5074, $event->latitude);
        $this->assertEquals(-0.1278, $event->longitude);
        $this->assertEquals('inherited', $event->location_source);
    }

    /**
     * @test
     */
    public function transaction_creates_place_and_links_event(): void
    {
        $this->assertEquals(0, Place::count());
        $this->assertEquals(0, Relationship::where('type', 'occurred_at')->count());

        $transaction = [
            'id' => 'tx_place_test',
            'created' => now()->toIso8601String(),
            'amount' => -1500,
            'currency' => 'GBP',
            'description' => 'Coffee Shop',
            'category' => 'eating_out',
            'merchant' => [
                'id' => 'merch_place',
                'name' => 'Starbucks',
                'online' => false,
                'latitude' => 51.5074,
                'longitude' => -0.1278,
                'address' => [
                    'address' => '123 High Street',
                    'city' => 'London',
                    'postcode' => 'SW1A 1AA',
                    'country' => 'GB',
                ],
            ],
        ];

        $this->plugin->processTransactionItem($this->integration, $transaction, 'acc_123');

        // Should create a place
        $this->assertEquals(1, Place::count());

        $place = Place::first();
        $this->assertEquals($this->user->id, $place->user_id);
        $this->assertNotNull($place->location);
        $this->assertEquals(1, $place->visit_count);
        $this->assertEquals('cafe', $place->category); // Auto-categorized from "Starbucks"

        // Event should be linked to place
        $this->assertEquals(1, Relationship::where('type', 'occurred_at')->count());

        $relationship = Relationship::where('type', 'occurred_at')->first();
        $event = Event::where('source_id', 'tx_place_test')->first();

        $this->assertEquals($event->id, $relationship->from_id);
        $this->assertEquals($place->id, $relationship->to_id);
    }

    /**
     * @test
     */
    public function subsequent_transactions_at_same_place_reuse_existing_place(): void
    {
        $transaction1 = [
            'id' => 'tx_place_1',
            'created' => now()->toIso8601String(),
            'amount' => -1000,
            'currency' => 'GBP',
            'description' => 'Coffee',
            'category' => 'eating_out',
            'merchant' => [
                'id' => 'merch_cafe',
                'name' => 'Local Cafe',
                'online' => false,
                'latitude' => 51.5074,
                'longitude' => -0.1278,
            ],
        ];

        $transaction2 = [
            'id' => 'tx_place_2',
            'created' => now()->addHour()->toIso8601String(),
            'amount' => -500,
            'currency' => 'GBP',
            'description' => 'Snack',
            'category' => 'eating_out',
            'merchant' => [
                'id' => 'merch_cafe',
                'name' => 'Local Cafe',
                'online' => false,
                'latitude' => 51.50741, // Slightly different (within 50m)
                'longitude' => -0.12781,
            ],
        ];

        $this->plugin->processTransactionItem($this->integration, $transaction1, 'acc_123');
        $this->assertEquals(1, Place::count());
        $place = Place::first();
        $this->assertEquals(1, $place->visit_count);

        $this->plugin->processTransactionItem($this->integration, $transaction2, 'acc_123');

        // Should still only have one place
        $this->assertEquals(1, Place::count());

        // Visit count should increment
        $place->refresh();
        $this->assertEquals(2, $place->visit_count);

        // Both events should be linked to the same place
        $this->assertEquals(2, Relationship::where('type', 'occurred_at')->count());
        $this->assertEquals(2, $place->relationshipsTo()->where('type', 'occurred_at')->count());
    }
}
