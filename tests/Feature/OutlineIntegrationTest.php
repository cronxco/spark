<?php

namespace Tests\Feature;

use App\Integrations\Outline\OutlineApi;
use App\Jobs\Outline\OutlineData;
use App\Jobs\Outline\PinTodayDayNote;
use App\Models\Block;
use App\Models\Event;
use App\Models\Integration;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OutlineIntegrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
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

    #[Test]
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

    #[Test]
    public function moved_tasks_with_same_titles_can_be_recreated_after_soft_delete(): void
    {
        $integration = $this->makeIntegration();

        $docBase = [
            'id' => 'doc-3',
            'title' => 'Test Document 3',
            'collectionId' => 'col-1',
            'createdAt' => now()->toIso8601String(),
            'url' => '/d/test-doc-3',
            'createdBy' => [
                'id' => 'user-1',
                'name' => 'Alice',
                'createdAt' => now()->subYear()->toIso8601String(),
                'avatarUrl' => null,
            ],
        ];

        (new OutlineData($integration, [
            'collections' => [],
            'documents' => [array_merge($docBase, [
                'text' => "- [ ] Reused\n- [ ] Other",
            ])],
        ]))->handle();

        (new OutlineData($integration, [
            'collections' => [],
            'documents' => [array_merge($docBase, [
                'text' => "- [ ] Other\n- [ ] Reused",
            ])],
        ]))->handle();

        $event = Event::where('integration_id', $integration->id)
            ->where('source_id', 'outline_doc_doc-3')
            ->firstOrFail();

        $blocks = $event->blocks()->withTrashed()->get();

        $this->assertSame(4, $blocks->count());
        $this->assertSame(2, $blocks->whereNull('deleted_at')->count());
        $this->assertSame(2, $blocks->whereNotNull('deleted_at')->count());
        $this->assertSame(['Other', 'Reused'], $blocks->whereNull('deleted_at')->pluck('title')->sort()->values()->all());
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

    #[Test]
    public function empty_outline_pages_stop_even_with_a_next_path(): void
    {
        Http::fake([
            '*' => Http::response(['data' => [], 'pagination' => ['nextPath' => '/api/documents.search?offset=100']], 200),
        ]);
        $api = new OutlineApi($this->makeIntegration());
        $this->assertSame([], $api->searchDocumentsLimited(['query' => '2026-10']));
        Http::assertSentCount(1);
    }

    #[Test]
    public function outline_total_stops_before_requesting_empty_pages(): void
    {
        Http::fake([
            '*' => Http::response([
                'data' => [['id' => 'doc']],
                'pagination' => ['nextPath' => '/api/documents.search?offset=100', 'offset' => 0, 'limit' => 100, 'total' => 31],
            ], 200),
        ]);

        $api = new OutlineApi($this->makeIntegration());
        $this->assertCount(1, $api->searchDocumentsLimited(['query' => '2026-10']));
        Http::assertSentCount(1);
    }

    #[Test]
    public function pin_job_joins_sibling_documents_and_removes_only_old_daynote_pins(): void
    {
        $integration = $this->makeIntegration();
        $title = now('UTC')->format('Y-m-d: l');
        Http::fake([
            '*documents.search*' => Http::response(['data' => [['document' => ['id' => 'today', 'title' => $title]]]], 200),
            '*pins.list*' => Http::response(['data' => [
                'pins' => [
                    ['id' => 'old-pin', 'documentId' => 'old'],
                    ['id' => 'project-pin', 'documentId' => 'project'],
                ],
                'documents' => [
                    ['id' => 'old', 'title' => '2026-01-01: Thursday'],
                    ['id' => 'project', 'title' => 'Spark Product'],
                ],
            ]], 200),
            '*pins.delete*' => Http::response(['success' => true], 200),
            '*pins.create*' => Http::response(['data' => ['id' => 'today-pin']], 200),
        ]);

        (new PinTodayDayNote($integration))->handle();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'pins.delete') && $request['id'] === 'old-pin');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'pins.delete') && $request['id'] === 'project-pin');
        Http::assertSent(fn ($request) => str_contains($request->url(), 'pins.create') && $request['documentId'] === 'today');
        $this->assertNotEmpty($integration->fresh()->configuration['last_pin_success_at']);
    }

    #[Test]
    public function failed_pin_creation_is_rethrown_and_does_not_record_success(): void
    {
        $integration = $this->makeIntegration();
        Http::fake([
            '*documents.search*' => Http::response(['data' => [['document' => ['id' => 'today', 'title' => now('UTC')->format('Y-m-d: l')]]]], 200),
            '*pins.list*' => Http::response(['data' => ['pins' => [], 'documents' => []]], 200),
            '*pins.create*' => Http::response(['error' => 'maximum_pins'], 400),
        ]);

        try {
            (new PinTodayDayNote($integration))->handle();
            $this->fail('Pin creation errors must fail the job.');
        } catch (Exception $e) {
            $this->assertStringContainsString('Outline API error: 400', $e->getMessage());
        }

        $config = $integration->fresh()->configuration;
        $this->assertNotEmpty($config['last_pin_attempt_at']);
        $this->assertArrayNotHasKey('last_pin_success_at', $config);
    }

}
