<?php

namespace App\Services\Knowledge;

use App\Models\Block;
use App\Models\EventObject;
use App\Models\Relationship;
use App\Models\User;
use App\Services\Api\ResourceVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ProjectService
{
    public function __construct(private ResourceVersion $versions, private TaskVersion $preconditions) {}

    public function query(User $user)
    {
        return EventObject::where('user_id', $user->id)->where('concept', 'knowledge')->where('type', 'project');
    }

    public function payload(EventObject $project): array
    {
        $m = $project->metadata ?? [];
        $tasks = app(TaskService::class);
        $ids = Relationship::where('user_id', $project->user_id)->where('to_type', EventObject::class)->where('to_id', $project->id)->where('from_type', Block::class)->where('type', 'task_project')->pluck('from_id');
        $items = $tasks->query($project->user)->whereIn('blocks.id', $ids)->get()->map(fn ($b) => $tasks->payload($b));
        $open = $items->where('status', 'open')->sortBy(fn ($p) => [$p['priority'] === 'important' ? 0 : 1, $p['due_on'] ?? '9999-12-31', $p['position'], $p['id']]);
        $next = $open->firstWhere('id', $m['next_action_task_id'] ?? null) ?? $open->first();

        return ['id' => (string) $project->id, 'title' => $project->title, 'content' => $project->content,
            'status' => $m['status'] ?? 'active', 'target_on' => $m['target_on'] ?? null,
            'hide_tasks' => $m['hide_tasks'] ?? true, 'next_action_task_id' => $m['next_action_task_id'] ?? null,
            'suggested_next_action_id' => $next['id'] ?? null,
            'progress' => ['total' => $items->count(), 'completed' => $items->where('status', 'completed')->count(), 'open' => $open->count(), 'expired' => $items->where('status', 'expired')->count()],
            'version' => $this->versions->etag($project)];
    }

    public function save(User $user, array $input, ?string $id = null, ?string $version = null): array
    {
        KnowledgeFeature::ensure($user, 'projects');
        KnowledgeFeature::ensure($user, 'writes');
        $data = Validator::make($input, [
            'title' => [$id ? 'sometimes' : 'required', 'required', 'string', 'max:255'],
            'content' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'status' => ['sometimes', 'in:active,paused,completed,archived'],
            'target_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'hide_tasks' => ['sometimes', 'boolean'],
            'next_action_task_id' => ['sometimes', 'nullable', 'uuid'],
        ])->validate();

        return DB::transaction(function () use ($user, $data, $id, $version) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $project = $id ? $this->query($user)->lockForUpdate()->findOrFail($id) : new EventObject(['user_id' => $user->id, 'concept' => 'knowledge', 'type' => 'project', 'time' => now()]);
            if ($id) {
                $this->preconditions->assert($project, $version);
            }
            if (isset($data['title'])) {
                abort_if($this->query($user)->where('title', $data['title'])->when($id, fn ($q) => $q->whereKeyNot($id))->exists(), 422, 'A project with this name already exists.');
            }
            if (! empty($data['next_action_task_id'])) {
                abort_unless($id && Relationship::where('user_id', $user->id)->where('from_type', Block::class)->where('from_id', $data['next_action_task_id'])->where('to_id', $id)->where('type', 'task_project')->exists(), 422, 'Choose a task in this project.');
                $next = app(TaskService::class)->find($user, $data['next_action_task_id']);
                abort_unless(app(TaskService::class)->payload($next)['status'] === 'open', 422, 'Choose an open task.');
            }
            $project->fill(collect($data)->only(['title', 'content'])->all());
            $project->metadata = array_merge($project->metadata ?? ['status' => 'active'], collect($data)->except(['title', 'content'])->all());
            $project->save();

            return $this->payload($project);
        });
    }

    public function delete(User $user, string $id, ?string $version): void
    {
        KnowledgeFeature::ensure($user, 'projects');
        KnowledgeFeature::ensure($user, 'writes');
        DB::transaction(function () use ($user, $id, $version) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $project = $this->query($user)->lockForUpdate()->findOrFail($id);
            $this->preconditions->assert($project, $version);
            $links = Relationship::where('user_id', $user->id)->where('to_type', EventObject::class)->where('to_id', $id)->where('type', 'task_project')->get();
            $tasks = app(TaskService::class);
            foreach ($tasks->query($user)->whereIn('blocks.id', $links->pluck('from_id'))->orderBy('blocks.id')->lockForUpdate()->get() as $block) {
                $state = $tasks->state($block);
                $tasks->save($block, $state, $block->title);
                $tasks->record($block, $user, 'project_removed', $state, $state);
            }
            // Tasks survive removal and return to an unlinked state.
            Relationship::where('user_id', $user->id)->where('to_type', EventObject::class)->where('to_id', $id)->where('type', 'task_project')->delete();
            $project->delete();
        });
    }
}
