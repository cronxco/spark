<?php

namespace Tests\Unit\Http\Resources;

use App\Http\Resources\EventResource;
use App\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EventResourceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_exposes_message_id_when_present_in_event_metadata()
    {
        $event = Event::factory()->create([
            'service' => 'receipt',
            'domain' => 'money',
            'action' => 'had_receipt_from',
            'event_metadata' => [
                'email_message_id' => '<invoice-abc123@digitalocean.com>',
            ],
        ]);

        $data = (new EventResource($event))->toArray(Request::create('/'));

        $this->assertSame('<invoice-abc123@digitalocean.com>', $data['message_id']);
    }

    #[Test]
    public function it_omits_message_id_when_not_present_in_event_metadata()
    {
        $event = Event::factory()->create([
            'service' => 'oura',
            'domain' => 'health',
            'action' => 'had_sleep_score',
            'event_metadata' => [],
        ]);

        $data = (new EventResource($event))->toArray(Request::create('/'));

        $this->assertArrayNotHasKey('message_id', $data);
    }
}
