<?php

namespace Tests\Feature;

use App\Jobs\Outline\OutlineData;
use App\Models\Block;
use App\Models\Event;
use App\Models\Integration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutlineIntegrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     */
    public function creates_task_blocks_from_document_text(): void
    {
        $integration = $this->makeIntegration();

        $collections = [
            [
                'id' => 'col-1',
                'name' => 'General',
                'description' => null,
                'createdAt' => now()->toIso8601String(),
                'url' => '/c/general',
            ],
        ];

        $documents = [
            [
                'id' => 'doc-1',
                'title' => 'Test Document',
                'collectionId' => 'col-1',
                'createdAt' => now()->toIso8601String(),
                'url' => '/d/test-doc',
                'text' => "- [ ] First task\n- [x] Done task\nNot a task",
                'createdBy' => [
                    'id' => 'user-1',
                    'name' => 'Alice',
                    'createdAt' => now()->subYear()->toIso8601String(),
                    'avatarUrl' => null,
                ],
            ],
        ];

        $job = new OutlineData($integration, [
            'collections' => $collections,
            'documents' => $documents,
        ]);
        $job->handle();

        $event = Event::where('integration_id', $integration->id)
            ->where('source_id', 'outline_doc_doc-1')
            ->first();

        $this->assertNotNull($event);

        $blocks = $event->blocks()->get();
        $this->assertCount(2, $blocks);

        // Ensure block types are doc_task (non day-note)
        $this->assertTrue($blocks->every(fn ($b) => $b->block_type === 'doc_task'));

        // Check checked metadata values
        $checkedValues = $blocks->pluck('metadata.checked')->all();
        sort($checkedValues);
        $this->assertSame([false, true], $checkedValues);
    }

    /**
     * @test
     */
    public function deleted_task_is_soft_deleted_on_reprocess(): void
    {
        $integration = $this->makeIntegration();

        $docBase = [
            'id' => 'doc-2',
            'title' => 'Test Document 2',
            'collectionId' => 'col-1',
            'createdAt' => now()->toIso8601String(),
            'url' => '/d/test-doc-2',
            'createdBy' => [
                'id' => 'user-1',
                'name' => 'Alice',
                'createdAt' => now()->subYear()->toIso8601String(),
                'avatarUrl' => null,
            ],
        ];

        // First run with two tasks
        $job1 = new OutlineData($integration, [
            'collections' => [
                [
                    'id' => 'col-1',
                    'name' => 'General',
                    'description' => null,
                    'createdAt' => now()->toIso8601String(),
                    'url' => '/c/general',
                ],
            ],
            'documents' => [array_merge($docBase, [
                'text' => "- [ ] A\n- [ ] B",
            ])],
        ]);
        $job1->handle();

        $event = Event::where('integration_id', $integration->id)
            ->where('source_id', 'outline_doc_doc-2')
            ->firstOrFail();

        $this->assertCount(2, $event->blocks);

        // Second run with one task removed
        $job2 = new OutlineData($integration, [
            'collections' => [],
            'documents' => [array_merge($docBase, [
                'text' => '- [ ] A',
            ])],
        ]);
        $job2->handle();

        $event->refresh();
        $blocks = $event->blocks()->withTrashed()->get();

        // One active, one soft-deleted
        $this->assertSame(2, $blocks->count());
        $this->assertSame(1, $blocks->whereNull('deleted_at')->count());
        $this->assertSame(1, $blocks->whereNotNull('deleted_at')->count());

        // The deleted one should have removal metadata set
        $deleted = $blocks->firstWhere('deleted_at', '!=', null);
        $this->assertTrue((bool) ($deleted->metadata['removed'] ?? false));
        $this->assertNotEmpty($deleted->metadata['removed_at'] ?? null);
    }

    private function makeIntegration(array $config = []): Integration
    {
        /** @var Integration $integration */
        $integration = Integration::factory()->create([
            'service' => 'outline',
            'instance_type' => 'pull',
            'configuration' => array_merge([
                'api_url' => 'https://example-outline.test',
                'access_token' => 'test-token',
                'daynotes_collection_id' => '5622670a-e725-437d-b747-a17905038df8',
                'poll_interval_minutes' => 15,
            ], $config),
        ]);

        return $integration;
    }
}
