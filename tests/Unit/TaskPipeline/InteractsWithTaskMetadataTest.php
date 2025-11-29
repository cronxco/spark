<?php

namespace Tests\Unit\TaskPipeline;

use App\Jobs\TaskPipeline\Concerns\InteractsWithTaskMetadata;
use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use Illuminate\Database\Eloquent\Model;
use Tests\TestCase;

class InteractsWithTaskMetadataTest extends TestCase
{
    use InteractsWithTaskMetadata;

    public function test_gets_correct_metadata_field_for_event(): void
    {
        $event = new Event();
        $field = $this->getMetadataField($event);

        $this->assertEquals('event_metadata', $field);
    }

    public function test_gets_correct_metadata_field_for_block(): void
    {
        $block = new Block();
        $field = $this->getMetadataField($block);

        $this->assertEquals('metadata', $field);
    }

    public function test_gets_correct_metadata_field_for_object(): void
    {
        $object = new EventObject();
        $field = $this->getMetadataField($object);

        $this->assertEquals('metadata', $field);
    }

    public function test_gets_task_executions_from_event(): void
    {
        $event = new Event();
        $event->event_metadata = [
            'task_executions' => [
                'test_task' => [
                    'last_attempt' => ['status' => 'success'],
                ],
            ],
        ];

        $executions = $this->getTaskExecutions($event);

        $this->assertIsArray($executions);
        $this->assertArrayHasKey('test_task', $executions);
        $this->assertEquals('success', $executions['test_task']['last_attempt']['status']);
    }

    public function test_gets_task_executions_from_block(): void
    {
        $block = new Block();
        $block->metadata = [
            'task_executions' => [
                'test_task' => [
                    'last_attempt' => ['status' => 'success'],
                ],
            ],
        ];

        $executions = $this->getTaskExecutions($block);

        $this->assertIsArray($executions);
        $this->assertArrayHasKey('test_task', $executions);
    }

    public function test_returns_empty_array_when_no_executions(): void
    {
        $event = new Event();
        $executions = $this->getTaskExecutions($event);

        $this->assertIsArray($executions);
        $this->assertEmpty($executions);
    }
}
