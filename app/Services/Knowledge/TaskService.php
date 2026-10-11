<?php

namespace App\Services\Knowledge;

use App\Jobs\Knowledge\SyncOutlineTask;
use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Relationship;
use App\Models\User;
use App\Services\Api\ResourceVersion;
use App\Services\EffectiveTimezoneResolver;
use App\Services\FlintDigestService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TaskService
{
    public const TYPES = ['knowledge_task', 'day_task', 'doc_task', 'flint_topic_task'];

    /**
     * Outline checklists in ordinary documents stay out of task lists; only day-page
     * tasks, thread tasks and tasks captured in Spark are shown.
     */
    public const LISTED_TYPES = ['knowledge_task', 'day_task', 'flint_topic_task'];

    public function __construct(
        private TaskLifecycle $lifecycle,
        private ResourceVersion $versions,
        private TaskVersion $preconditions,
        private EffectiveTimezoneResolver $timezones,
        private FlintDigestService $digests,
    ) {}

    public function query(User $user, bool $deleted = false): Builder
    {
        $query = Block::query();
        $table = $query->getQuery()->getGrammar()->wrapTable($query->getModel()->getTable());

        return $query->when($deleted, fn ($q) => $q->withTrashed())
            ->selectRaw($table . '.*')->selectRaw($table . '.xmin AS xmin')
            ->with(['event.integration.user', 'relationshipsFrom' => fn ($q) => $q->where('user_id', $user->id)->whereNull('deleted_at')->whereIn('type', ['task_project', 'task_thread', 'task_context']), 'relationshipsFrom.to'])
            ->whereIn('block_type', self::LISTED_TYPES)
            ->whereHas('event', fn ($q) => $q->whereNull('deleted_at')
                ->whereHas('integration', fn ($q) => $q->where('user_id', $user->id)));
    }

    public function plannedQuery(User $user, string $date): Builder
    {
        return $this->query($user)->where(function (Builder $query) use ($user, $date) {
            $query->where(function (Builder $native) use ($date) {
                $this->plannedState($native, 'metadata->task', $date);
            })->orWhere(function (Builder $legacy) use ($date) {
                $legacy->whereNull('metadata->task')
                    ->whereRaw("COALESCE(metadata->>'removed', 'false') != 'true'")
                    ->where(function (Builder $types) use ($date) {
                        $types->where(fn (Builder $day) => $day->where('block_type', 'day_task')
                            ->where(fn (Builder $window) => $window
                                ->whereHas('event', fn (Builder $event) => $event->whereDate('time', $date))
                                ->orWhere(fn (Builder $carried) => $carried->whereNull('metadata->completed_at')
                                    ->whereRaw("COALESCE(metadata->>'checked', 'false') != 'true'")
                                    ->whereHas('event', fn (Builder $event) => $event->whereDate('time', '<', $date)
                                        ->whereDate('time', '>=', CarbonImmutable::parse($date)->subDays(6)->toDateString())))))
                            ->orWhere(function (Builder $other) use ($date) {
                                $other->where('block_type', '!=', 'day_task')
                                    ->whereRaw("LEAST(metadata->>'due_on', metadata->>'review_on') <= ?", [$date])
                                    ->where(fn (Builder $status) => $status
                                        ->where(fn (Builder $open) => $open->whereNull('metadata->completed_at')->whereRaw("COALESCE(metadata->>'checked', 'false') != 'true'"))
                                        ->orWhereRaw("LEFT(metadata->>'completed_at', 10) = ?", [$date])
                                        ->orWhereRaw("LEAST(metadata->>'due_on', metadata->>'review_on') = ?", [$date]));
                            });
                    });
            });
            // Historical projections use snapshots, even if the current task was
            // completed, expired, deferred or cancelled after the requested day.
            if ($date < CarbonImmutable::now('UTC')->addHours(14)->toDateString()) {
                $history = Event::query()->selectRaw("(event_metadata->>'task_id')::uuid")
                    ->where('source_id', 'like', 'task_history:%')
                    ->whereHas('integration', fn (Builder $integration) => $integration->where('user_id', $user->id))
                    ->whereNotNull('event_metadata->after')
                    ->whereRaw("? < (?::timestamptz AT TIME ZONE COALESCE(event_metadata->'after'->>'schedule_timezone', 'UTC'))::date", [$date, CarbonImmutable::now('UTC')->toIso8601String()])
                    ->whereRaw("(time AT TIME ZONE COALESCE(event_metadata->'after'->>'schedule_timezone', 'UTC'))::date <= ?", [$date]);
                $this->plannedState($history, 'event_metadata->after', $date);
                $query->orWhereIn('id', $history);
            }
        });
    }

    public function find(User $user, string $id, bool $deleted = false): Block
    {
        return $this->query($user, $deleted)->findOrFail($id);
    }

    public function validate(array $input, bool $create = false): array
    {
        return Validator::make($input, [
            'title' => [$create ? 'required' : 'sometimes', 'required', 'string', 'max:255', 'not_regex:/[\r\n]/'],
            'client_mutation_id' => [$create ? 'required' : 'sometimes', 'uuid'],
            'content' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'lifecycle' => ['sometimes', 'in:day,week,persistent'],
            'availability_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'due_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'review_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'remind_at' => ['sometimes', 'nullable', 'date'],
            'schedule_timezone' => ['sometimes', 'timezone'],
            'priority' => ['sometimes', 'in:normal,important'],
            'position' => ['sometimes', 'integer', 'min:0'],
            'project_id' => ['sometimes', 'nullable', 'uuid'],
            'thread_ids' => ['sometimes', 'array', 'max:20'],
            'thread_ids.*' => ['uuid', 'distinct'],
            'context' => ['sometimes', 'array', 'max:20'],
            'context.*.kind' => ['required', 'in:event,object,block'],
            'context.*.id' => ['required', 'uuid'],
            'recurrence' => ['sometimes', 'nullable', 'in:daily,weekly,monthly'],
            'completed' => ['sometimes', 'boolean'],
        ])->validate();
    }

    public function state(Block $block): array
    {
        $m = $block->metadata ?? [];
        if (isset($m['task'])) {
            return $m['task'];
        }
        $day = $block->block_type === 'day_task';
        $dates = array_filter([$m['due_on'] ?? null, $m['review_on'] ?? null]);
        $start = $day ? ($block->event?->time ?? $block->time)?->toDateString() : ($dates ? min($dates) : null);

        return $this->lifecycle->schedule([
            'schema_version' => 1, 'lifecycle' => $day ? 'week' : 'persistent',
            'status' => ($m['removed'] ?? false) ? 'deleted' : (($m['checked'] ?? false) || ! empty($m['completed_at']) ? 'completed' : 'open'),
            'availability_on' => $start, 'original_assigned_on' => $start, 'window_start_on' => $start,
            'schedule_timezone' => $this->timezones->timezoneFor($block->event?->integration?->user), 'due_on' => $m['due_on'] ?? null, 'review_on' => $m['review_on'] ?? null,
            'priority' => 'normal', 'content' => $m['content'] ?? null, 'completed_at' => $m['completed_at'] ?? null,
            'origin' => str_starts_with($block->block_type, 'flint') ? 'thread' : 'outline',
        ]);
    }

    public function payload(Block $block, ?string $date = null): array
    {
        $task = $this->state($block);
        $title = $block->title;
        $historicallyAbsent = false;
        if ($date && $date < CarbonImmutable::now($task['schedule_timezone'])->toDateString()) {
            $cutoff = CarbonImmutable::parse($date, $task['schedule_timezone'])->endOfDay()->utc();
            $history = Event::where('integration_id', $block->event->integration_id)->where('event_metadata->task_id', (string) $block->id)->whereNotNull('event_metadata->after')->where('time', '<=', $cutoff)->orderByDesc('time')->orderByRaw("COALESCE((event_metadata->>'sequence')::bigint, 0) DESC")->orderByDesc('created_at')->orderByDesc('id')->first();
            if ($history) {
                $task = data_get($history->event_metadata, 'after');
                $title = data_get($history->event_metadata, 'title', $title);
            } elseif ($block->block_type === 'knowledge_task' && $block->created_at->gt($cutoff)) {
                $historicallyAbsent = true;
            }
        }
        $date ??= CarbonImmutable::now($task['schedule_timezone'] ?? 'UTC')->toDateString();
        $links = $block->relationLoaded('relationshipsFrom') ? $block->relationshipsFrom->whereNull('deleted_at')->whereIn('type', ['task_project', 'task_thread', 'task_context'])
            : Relationship::where('user_id', $block->event->integration->user_id)->where('from_type', Block::class)->where('from_id', $block->id)->whereIn('type', ['task_project', 'task_thread', 'task_context'])->with('to')->get();
        $project = $links->firstWhere('type', 'task_project')?->to;
        $paused = $project && data_get($project->metadata, 'status') === 'paused' && data_get($project->metadata, 'hide_tasks', true);
        $start = $task['availability_on'] ?? null;
        $status = $block->trashed() ? 'deleted' : $this->lifecycle->status($task, $date);

        return [
            'id' => (string) $block->id, 'title' => $title, 'version' => $this->versions->etag($block),
            'content' => $task['content'] ?? null, 'lifecycle' => $task['lifecycle'], 'status' => $status,
            'availability_on' => $start, 'original_assigned_on' => $task['original_assigned_on'] ?? $start,
            'expires_on' => $task['expires_on'] ?? null, 'due_on' => $task['due_on'] ?? null,
            'review_on' => $task['review_on'] ?? null, 'schedule_timezone' => $task['schedule_timezone'],
            'completed_at' => $task['completed_at'] ?? null, 'priority' => $task['priority'] ?? 'normal',
            'position' => $task['position'] ?? 0, 'origin' => $task['origin'] ?? 'native',
            'carried_days' => $start && $date > $start ? (int) CarbonImmutable::parse($start)->diffInDays(CarbonImmutable::parse($date)) : 0,
            'overdue' => $status === 'open' && ! empty($task['due_on']) && $task['due_on'] < $date,
            'visible_on_date' => ! $historicallyAbsent && ! $block->trashed() && $this->lifecycle->visible($task, $date) && (! $paused || isset($task['incident'])),
            'project_id' => $project?->id, 'project_title' => $project?->title,
            'thread_ids' => $links->where('type', 'task_thread')->pluck('to_id')->push($block->block_type === 'flint_topic_task' && empty($task['thread_links_migrated']) ? $block->event?->target_id : null)->filter()->unique()->values()->all(),
            'context' => $links->where('type', 'task_context')->map(fn ($r) => ['kind' => match ($r->to_type) {
                Event::class => 'event', Block::class => 'block', default => 'object'
            }, 'id' => $r->to_id, 'title' => $r->to?->title ?? $r->to?->action])->values()->all(),
            'source_url' => $block->url, 'sync' => $task['sync'] ?? ['status' => 'synced'],
            'remind_at' => $task['remind_at'] ?? null, 'recurrence' => $task['recurrence'] ?? null,
            'action' => $task['action'] ?? null,
        ];
    }

    public function create(User $user, array $input): array
    {
        KnowledgeFeature::ensure($user, 'writes');
        $data = $this->validate($input, true);
        $hash = PayloadHash::of($data);

        return DB::transaction(function () use ($user, $data, $hash) {
            // The owner row serializes creation even before the assistant exists.
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $source = 'knowledge_task:' . $data['client_mutation_id'];
            $integration = $this->digests->resolveIntegration($user);
            $existing = Event::where('integration_id', $integration->id)->where('source_id', $source)->first();
            if ($existing) {
                abort_unless(data_get($existing->event_metadata, 'request_hash') === $hash, 409, 'This mutation ID was used with different content.');

                return $this->payload($existing->blocks()->withTrashed()->firstOrFail());
            }
            $task = $this->lifecycle->schedule(array_merge([
                'schema_version' => 1, 'lifecycle' => 'persistent', 'status' => 'open',
                'origin' => 'native', 'availability_on' => null, 'priority' => 'normal',
                'schedule_timezone' => $this->timezones->timezoneFor($user),
            ], collect($data)->except(['title', 'client_mutation_id', 'project_id', 'thread_ids', 'context'])->all()));
            $task['original_assigned_on'] = $task['availability_on'];
            $task['window_start_on'] = $task['availability_on'];
            if (! empty($task['remind_at'])) {
                $task['remind_at'] = CarbonImmutable::parse($task['remind_at'], $task['schedule_timezone'])->utc()->toIso8601String();
            }
            if (! array_key_exists('due_on', $data) && $task['lifecycle'] !== 'persistent') {
                $task['due_on'] = CarbonImmutable::parse($task['expires_on'])->subDay()->toDateString();
            }
            $actor = EventObject::firstOrCreate(['user_id' => $user->id, 'concept' => 'user', 'type' => 'user_profile', 'title' => $user->name], ['time' => now()]);
            $event = Event::create([
                'integration_id' => $integration->id, 'source_id' => $source,
                'actor_id' => $actor->id, 'target_id' => $actor->id,
                'service' => 'flint', 'domain' => 'knowledge', 'action' => 'created_task', 'time' => now(),
                'event_metadata' => ['internal' => true, 'request_hash' => $hash],
            ]);
            $block = Block::create(['event_id' => $event->id, 'block_type' => 'knowledge_task', 'title' => $data['title'], 'time' => now(), 'metadata' => ['task' => $task]]);
            $this->links($user, $block, $data);
            if (! empty($task['recurrence'])) {
                $task = app(TaskRecurrenceService::class)->configure($user, $block, $task);
            }
            $this->save($block, $task, $block->title);
            $this->record($block, $user, 'created', null, $task);

            return $this->payload($block);
        });
    }

    public function update(User $user, string $id, array $input, ?string $version): array
    {
        KnowledgeFeature::ensure($user, 'writes');
        $data = $this->validate($input);

        return DB::transaction(function () use ($user, $id, $data, $version) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $block = $this->query($user)->lockForUpdate()->findOrFail($id);
            if ($replay = $this->replay($block, $data['client_mutation_id'] ?? null, ['edit' => $data])) {
                return $replay;
            }
            $this->preconditions->assert($block, $version);
            $before = $this->state($block);
            $task = array_merge($before, collect($data)->except(['title', 'client_mutation_id', 'project_id', 'thread_ids', 'context'])->all());
            if (array_key_exists('completed', $data)) {
                abort_if(isset($task['incident']), 409, 'Use the recovery action to resolve this task.');
                abort_if($this->lifecycle->status($before, CarbonImmutable::now($before['schedule_timezone'])->toDateString()) === 'expired', 409, 'Reactivate this task first.');
                $task['status'] = $data['completed'] ? 'completed' : 'open';
                $task['completed_at'] = $data['completed'] ? ($before['completed_at'] ?? now()->toIso8601String()) : null;
                unset($task['completed']);
            }
            if (array_key_exists('availability_on', $data) && $task['lifecycle'] === 'day') {
                $task['window_start_on'] = $data['availability_on'];
            }
            if (isset($data['lifecycle']) && $data['lifecycle'] !== $before['lifecycle']) {
                $task['window_start_on'] = $task['availability_on'];
            }
            $task = $this->lifecycle->schedule($task);
            if (! empty($task['remind_at'])) {
                $task['remind_at'] = CarbonImmutable::parse($task['remind_at'], $task['schedule_timezone'])->utc()->toIso8601String();
            }
            if (! empty($task['availability_on']) && ! empty($task['expires_on']) && $task['availability_on'] >= $task['expires_on']) {
                throw ValidationException::withMessages(['availability_on' => 'This date is outside the task window. Reactivate to start a new window.']);
            }
            if (array_key_exists('thread_ids', $data)) {
                $task['thread_links_migrated'] = true;
            }
            $this->links($user, $block, $data);
            $title = $data['title'] ?? $block->title;
            $task = $this->pendingOutline($block, $task, $title);
            if (array_key_exists('recurrence', $data)) {
                $task = app(TaskRecurrenceService::class)->configure($user, $block, $task);
            }
            $this->save($block, $task, $title);
            $this->record($block, $user, 'edited', $before, $task);
            $this->receipt($block, $data['client_mutation_id'] ?? null, ['edit' => $data]);

            return $this->payload($block);
        });
    }

    public function act(User $user, string $id, string $action, ?string $version, array $input = []): array
    {
        KnowledgeFeature::ensure($user, 'writes');

        return DB::transaction(function () use ($user, $id, $action, $version, $input) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $block = $this->query($user, true)->lockForUpdate()->findOrFail($id);
            $mutation = Validator::make($input, ['client_mutation_id' => ['sometimes', 'uuid']])->validate()['client_mutation_id'] ?? null;
            if ($replay = $this->replay($block, $mutation, [$action => $input])) {
                return $replay;
            }
            $this->preconditions->assert($block, $version);
            $before = $task = $this->state($block);
            $today = CarbonImmutable::now($task['schedule_timezone'])->toDateString();
            abort_if($block->trashed() && $action !== 'restore', 409, 'Restore this task first.');
            switch ($action) {
                case 'complete':
                    abort_if(isset($task['incident']), 409, 'Use the recovery action; Spark verifies the connection before resolving this task.');
                    abort_if($this->lifecycle->status($task, $today) === 'expired', 409, 'This task expired. Reactivate it first.');
                    $task['status'] = 'completed';
                    $task['completed_at'] = now()->toIso8601String();
                    break;
                case 'reopen':
                    abort_if(! empty($task['expires_on']) && $today >= $task['expires_on'], 409, 'Choose a new date with Reactivate.');
                    $task['status'] = 'open';
                    $task['completed_at'] = null;
                    break;
                case 'cancel': $task['status'] = 'cancelled';
                    $task['cancelled_at'] = now()->toIso8601String();
                    break;
                case 'reactivate':
                    $data = Validator::make($input, ['availability_on' => ['required', 'date_format:Y-m-d']])->validate();
                    abort_if($data['availability_on'] < $today, 422, 'Choose today or a future date.');
                    $task['availability_on'] = $task['window_start_on'] = $data['availability_on'];
                    $task['status'] = 'open';
                    $task['completed_at'] = null;
                    $task = $this->lifecycle->schedule($task);
                    break;
                case 'delete': $block->delete();
                    break;
                case 'restore': $block->restore();
                    break;
                case 'retry_sync':
                    abort_unless(in_array($block->block_type, ['day_task', 'doc_task']) && isset($task['sync']['mutation_id']), 422, 'No Outline edit to retry.');
                    $task['sync']['status'] = 'pending';
                    $task['sync']['reason'] = null;
                    DB::afterCommit(fn () => SyncOutlineTask::dispatch((string) $block->id)->onQueue('pull'));
                    break;
                default: abort(422, 'Unknown task action.');
            }
            $task = $this->pendingOutline($block, $task, $block->title, $action);
            $this->save($block, $task, $block->title);
            $this->record($block, $user, $action, $before, $task);
            $this->receipt($block, $mutation, [$action => $input]);

            return $this->payload($block);
        });
    }

    public function save(Block $block, array $task, string $title): void
    {
        $this->isolateTitleCollision($block, $title, $block->block_type);
        $m = $block->metadata ?? [];
        $task['revision'] = (data_get($m, 'task.revision') ?? 0) + 1;
        $m['task'] = $task;
        $m['content'] = $task['content'] ?? null;
        $m['checked'] = $task['status'] === 'completed';
        $m['completed_at'] = $task['completed_at'] ?? null;
        $m['due_on'] = $task['due_on'] ?? null;
        $m['review_on'] = $task['review_on'] ?? null;
        $block->update(['title' => $title, 'metadata' => $m]);
        $block->offsetUnset('xmin');
        $block->unsetRelation('relationshipsFrom');
    }

    public function isolateTitleCollision(Block $block, string $title, string $type): void
    {
        if (! Block::where('event_id', $block->event_id)->where('title', $title)->where('block_type', $type)->whereKeyNot($block->id)->exists()) {
            return;
        }
        $parent = $block->event;
        $carrier = Event::create(['integration_id' => $parent->integration_id, 'source_id' => 'outline_task:' . Str::uuid(),
            'actor_id' => $parent->actor_id, 'target_id' => $parent->target_id, 'service' => $parent->service, 'domain' => 'knowledge',
            'action' => 'had_task', 'time' => $parent->time, 'event_metadata' => ['internal' => true, 'outline_parent_event_id' => (string) $parent->id]]);
        $block->event_id = $carrier->id;
        $block->setRelation('event', $carrier);
    }

    public function record(Block $block, User $user, string $action, ?array $before, array $after): void
    {
        $after = $this->state($block);
        Event::create([
            'integration_id' => $block->event->integration_id, 'source_id' => 'task_history:' . Str::uuid(),
            'actor_id' => $block->event->actor_id, 'target_id' => $block->event->target_id,
            'service' => 'flint', 'domain' => 'knowledge', 'action' => 'task_' . $action, 'time' => now(),
            'event_metadata' => ['internal' => true, 'task_id' => (string) $block->id, 'actor_id' => $user->id, 'sequence' => $after['revision'] ?? 0, 'before' => $before, 'after' => $after, 'title' => $block->title],
        ]);
    }

    public function history(User $user, string $id): Builder
    {
        $block = $this->find($user, $id, true);

        return Event::where('integration_id', $block->event->integration_id)->where('event_metadata->task_id', $id)->orderByDesc('time')->orderByDesc('id');
    }

    private function plannedState(Builder $query, string $path, string $date): void
    {
        $query->where(fn (Builder $state) => $state
            ->where(fn (Builder $open) => $open->where($path . '->availability_on', '<=', $date)->where($path . '->status', 'open')
                ->where(fn (Builder $expiry) => $expiry->whereNull($path . '->expires_on')->orWhere($path . '->expires_on', '>', $date)))
            ->orWhere(fn (Builder $completed) => $completed->where($path . '->status', 'completed')
                ->where(fn (Builder $day) => $day->where($path . '->availability_on', $date)
                    ->orWhereRaw($query->getQuery()->getGrammar()->wrap($path . '->completed_at') . ' LIKE ?', [$date . '%']))));
    }

    private function pendingOutline(Block $block, array $task, string $title, ?string $action = null): array
    {
        if (! in_array($block->block_type, ['day_task', 'doc_task'])) {
            return $task;
        }
        $sourceChange = $title !== $block->title || ($task['status'] === 'completed') !== ($this->state($block)['status'] === 'completed') || in_array($action, ['delete', 'restore']) || ($task['content'] ?? null) !== ($this->state($block)['content'] ?? null);
        if ($sourceChange) {
            $task['sync'] = array_merge($task['sync'] ?? [], [
                'status' => 'pending', 'mutation_id' => (string) Str::uuid(),
                'desired_title' => $title, 'desired_checked' => $task['status'] === 'completed',
                'desired_content' => $task['content'] ?? null,
                'desired_deleted' => $action === 'delete', 'requested_at' => now()->toIso8601String(),
            ]);
            DB::afterCommit(fn () => SyncOutlineTask::dispatch((string) $block->id)->onQueue('pull'));
        }

        return $task;
    }

    private function links(User $user, Block $block, array $data): void
    {
        foreach (['project_id' => 'task_project', 'thread_ids' => 'task_thread', 'context' => 'task_context'] as $key => $type) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            $targets = $key === 'project_id' ? ($data[$key] ? [['kind' => 'object', 'id' => $data[$key]]] : [])
                : ($key === 'thread_ids' ? array_map(fn ($id) => ['kind' => 'object', 'id' => $id], $data[$key]) : $data[$key]);
            $valid = [];
            foreach ($targets as $target) {
                $class = match ($target['kind']) {
                    'block' => Block::class, 'event' => Event::class, default => EventObject::class
                };
                $query = $class::query()->whereKey($target['id']);
                if ($class === EventObject::class) {
                    $query->where('user_id', $user->id);
                    if ($type === 'task_project') {
                        $query->where('concept', 'knowledge')->where('type', 'project');
                    }
                    if ($type === 'task_thread') {
                        $query->where('concept', 'flint')->where('type', 'topic');
                    }
                } else {
                    if ($class === Block::class) {
                        $query->whereHas('event.integration', fn ($q) => $q->where('user_id', $user->id));
                    } else {
                        $query->whereHas('integration', fn ($q) => $q->where('user_id', $user->id));
                    }
                }
                $valid[] = [$class, $query->firstOrFail()->id];
            }
            Relationship::where('user_id', $user->id)->where('from_type', Block::class)->where('from_id', $block->id)->where('type', $type)->delete();
            foreach ($valid as [$class, $id]) {
                Relationship::create(['user_id' => $user->id, 'from_type' => Block::class, 'from_id' => $block->id, 'to_type' => $class, 'to_id' => $id, 'type' => $type]);
            }
        }
    }

    private function replay(Block $block, ?string $mutation, array $request): ?array
    {
        if (! $mutation) {
            return null;
        }
        $receipt = Event::whereHas('integration', fn ($q) => $q->where('user_id', $block->event->integration->user_id))->where('source_id', 'knowledge_mutation:' . $mutation)->first();
        if (! $receipt) {
            return null;
        }
        abort_unless(data_get($receipt->event_metadata, 'task_id') === (string) $block->id && data_get($receipt->event_metadata, 'hash') === PayloadHash::of($request), 409, 'Mutation ID reused with different content.');

        return data_get($receipt->event_metadata, 'result');
    }

    private function receipt(Block $block, ?string $mutation, array $request): void
    {
        if (! $mutation) {
            return;
        }
        Event::create(['integration_id' => $block->event->integration_id, 'source_id' => 'knowledge_mutation:' . $mutation,
            'actor_id' => $block->event->actor_id, 'target_id' => $block->event->target_id, 'service' => 'flint', 'domain' => 'knowledge',
            'action' => 'task_mutation', 'time' => now(), 'event_metadata' => ['internal' => true, 'task_id' => (string) $block->id,
                'hash' => PayloadHash::of($request), 'result' => $this->payload($block)]]);
    }
}
