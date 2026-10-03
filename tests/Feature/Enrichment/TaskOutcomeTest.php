<?php

namespace Tests\Feature\Enrichment;

use App\Jobs\TaskPipeline\Tasks\DownloadImagesToMediaLibraryTask;
use App\Jobs\TaskPipeline\Tasks\GenerateEmbeddingTask;
use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\TaskExecution;
use App\Services\Ai\EmbeddingClient;
use App\Services\Media\MediaDownloadHelper;
use App\Services\TaskPipeline\TaskDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

/**
 * ENR-04: a task's "success" must mean it produced what it promises. An
 * image task that stored no image fails; one that stored some records a
 * warning; work with nothing to do says so.
 */
class TaskOutcomeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.enable_task_pipeline' => false]);
    }

    #[Test]
    public function an_image_task_that_stores_no_image_fails(): void
    {
        $this->downloads([null, null]);
        $event = $this->eventWithImages(2);

        $this->runImageTask($event);

        $execution = $this->execution($event, 'download_images_to_media_library');
        $this->assertSame('failed', $execution->status);
        $this->assertSame('None of the 2 images could be downloaded.', $execution->error);
        $this->assertNull($execution->last_success);
    }

    #[Test]
    public function an_image_task_that_stores_some_images_records_a_warning(): void
    {
        $this->downloads([new Media, null]);
        $event = $this->eventWithImages(2);

        $this->runImageTask($event);

        $execution = $this->execution($event, 'download_images_to_media_library');
        $this->assertSame('success', $execution->status);
        $this->assertSame('succeeded_with_warnings', $execution->last_success['outcome']);
        $this->assertEquals(['needed' => 2, 'downloaded' => 1, 'missing' => 1], $execution->last_success['outcome_counts']);
    }

    #[Test]
    public function an_image_task_that_stores_every_image_succeeds(): void
    {
        $this->downloads([new Media, new Media]);
        $event = $this->eventWithImages(2);

        $this->runImageTask($event);

        $this->assertSame('succeeded', $this->execution($event, 'download_images_to_media_library')->last_success['outcome']);
    }

    #[Test]
    public function an_image_task_with_no_images_is_not_applicable(): void
    {
        $this->downloads([]);
        $event = $this->eventWithImages(0);

        $this->runImageTask($event);

        $this->assertSame('not_applicable', $this->execution($event, 'download_images_to_media_library')->last_success['outcome']);
    }

    #[Test]
    public function an_embedding_with_no_text_is_not_applicable(): void
    {
        $model = Mockery::mock(EmbeddingClient::class);
        $model->shouldReceive('embed')->never();
        $this->instance(EmbeddingClient::class, $model);

        $block = Block::factory()->create([
            'event_id' => Event::factory()->create()->id,
            'title' => '',
            'url' => null,
            'value' => null,
            'metadata' => [],
        ]);

        (new GenerateEmbeddingTask($block, new TaskDefinition(
            key: 'generate_embedding',
            name: 'Generate Embedding',
            description: 'Generate AI embedding',
            jobClass: GenerateEmbeddingTask::class,
            appliesTo: ['block'],
        )))->handle();

        $this->assertSame('not_applicable', $this->execution($block, 'generate_embedding')->last_success['outcome']);
    }

    /**
     * @param  list<Media|null>  $results
     */
    private function downloads(array $results): void
    {
        $helper = Mockery::mock(MediaDownloadHelper::class);
        $helper->shouldReceive('downloadAndAttachMedia')->times(count($results))->andReturnValues($results);
        $this->instance(MediaDownloadHelper::class, $helper);
    }

    private function eventWithImages(int $count): Event
    {
        $event = Event::factory()->create([
            'target_id' => EventObject::factory()->create(['media_url' => null])->id,
            'actor_id' => EventObject::factory()->create(['media_url' => null])->id,
        ]);

        for ($i = 0; $i < $count; $i++) {
            Block::factory()->create([
                'event_id' => $event->id,
                'media_url' => "https://images.example/{$i}.jpg",
                'metadata' => [],
            ]);
        }

        return $event->fresh();
    }

    private function runImageTask(Event $event): void
    {
        (new DownloadImagesToMediaLibraryTask($event, new TaskDefinition(
            key: 'download_images_to_media_library',
            name: 'Download images',
            description: 'Download images',
            jobClass: DownloadImagesToMediaLibraryTask::class,
            appliesTo: ['event'],
        )))->handle();
    }

    private function execution(Event|Block $entity, string $taskKey): TaskExecution
    {
        return TaskExecution::query()->where('entity_id', $entity->id)->where('task_key', $taskKey)->firstOrFail();
    }
}
