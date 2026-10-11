<?php

namespace Tests\Feature;

use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\User;
use App\Services\Knowledge\TaskService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class KnowledgeTaskListingRulesTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 14)->setTime(12, 0));
        $this->user = User::factory()->create();
    }

    #[Test]
    public function only_day_page_outline_tasks_are_listed(): void
    {
        $dayTask = $this->outlineTask('day_task', 'Day page task', 'had_day_note');
        $this->outlineTask('doc_task', 'Document task', 'had_document');

        $titles = app(TaskService::class)->query($this->user)->pluck('title')->all();

        $this->assertSame(['Day page task'], $titles);
        $this->assertNotNull($dayTask);
    }

    #[Test]
    public function day_page_tasks_import_as_week_tasks(): void
    {
        $block = $this->outlineTask('day_task', 'Day page task', 'had_day_note', '2026-10-11');

        $payload = app(TaskService::class)->payload($block);

        $this->assertSame('week', $payload['lifecycle']);
        $this->assertSame('2026-10-18', $payload['expires_on']);
        $this->assertSame('open', $payload['status']);
    }

    #[Test]
    public function a_week_task_expires_after_seven_days(): void
    {
        $block = $this->outlineTask('day_task', 'Day page task', 'had_day_note', '2026-10-07');

        $this->assertSame('expired', app(TaskService::class)->payload($block)['status']);
    }

    #[Test]
    public function thread_tasks_persist(): void
    {
        $flint = Integration::factory()->create(['user_id' => $this->user->id, 'service' => 'flint']);
        $topic = EventObject::factory()->create(['user_id' => $this->user->id, 'concept' => 'flint', 'type' => 'topic', 'title' => 'Marathon base']);
        $event = Event::factory()->create(['integration_id' => $flint->id, 'service' => 'flint', 'action' => 'had_topic_task', 'target_id' => $topic->id, 'event_metadata' => ['internal' => true]]);
        $block = Block::factory()->create(['event_id' => $event->id, 'block_type' => 'flint_topic_task', 'title' => 'Book physio', 'metadata' => ['completed_at' => null]]);

        $payload = app(TaskService::class)->payload($block);

        $this->assertSame('persistent', $payload['lifecycle']);
        $this->assertNull($payload['expires_on']);
    }

    #[Test]
    public function open_day_page_tasks_carry_over_for_the_week_but_not_beyond(): void
    {
        $this->outlineTask('day_task', 'Carried', 'had_day_note', '2026-10-11');
        $tasks = app(TaskService::class);

        $this->assertContains('Carried', $tasks->plannedQuery($this->user, '2026-10-14')->pluck('title')->all());
        $this->assertContains('Carried', $tasks->plannedQuery($this->user, '2026-10-17')->pluck('title')->all());
        $this->assertNotContains('Carried', $tasks->plannedQuery($this->user, '2026-10-18')->pluck('title')->all());
    }

    private function outlineTask(string $type, string $title, string $action, string $date = '2026-10-14'): Block
    {
        $outline = Integration::factory()->create(['user_id' => $this->user->id, 'service' => 'outline']);
        $event = Event::factory()->create([
            'integration_id' => $outline->id,
            'service' => 'outline',
            'action' => $action,
            'time' => $date . ' 00:00:00',
            'target_id' => EventObject::factory()->create(['user_id' => $this->user->id])->id,
        ]);

        return Block::factory()->create(['event_id' => $event->id, 'block_type' => $type, 'title' => $title, 'metadata' => ['line_number' => 1, 'checked' => false]]);
    }
}
