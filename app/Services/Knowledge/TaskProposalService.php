<?php

namespace App\Services\Knowledge;

use App\Models\EventObject;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Ramsey\Uuid\Uuid;

class TaskProposalService
{
    public function propose(User $user, array $input): array
    {
        $meta = Validator::make($input, ['reason' => ['required', 'string', 'max:2000'], 'source_key' => ['required', 'string', 'max:255']])->validate();
        $data = app(TaskService::class)->validate($input + ['client_mutation_id' => (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'spark:proposal:' . $user->id . ':' . $meta['source_key'])], true);

        return DB::transaction(function () use ($user, $data, $meta) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $proposal = EventObject::firstOrCreate(['user_id' => $user->id, 'concept' => 'knowledge', 'type' => 'task_proposal', 'title' => hash('sha256', $meta['source_key'])],
                ['time' => now(), 'metadata' => ['internal' => true, 'status' => 'pending', 'task' => $data, 'reason' => $meta['reason']]]);

            return $this->payload($proposal);
        });
    }

    public function items(User $user): array
    {
        return EventObject::where('user_id', $user->id)->where('concept', 'knowledge')->where('type', 'task_proposal')->where('metadata->status', 'pending')->latest('created_at')->limit(50)->get()->map(fn ($p) => $this->payload($p))->all();
    }

    public function payload(EventObject $p): array
    {
        return ['id' => (string) $p->id, 'kind' => 'task_proposal', 'title' => data_get($p->metadata, 'task.title'), 'summary' => data_get($p->metadata, 'reason'),
            'confidence' => null, 'created_at' => $p->created_at?->toIso8601String(), 'subject' => ['id' => (string) $p->id, 'title' => data_get($p->metadata, 'task.title'), 'service' => 'flint'],
            'candidates' => [], 'actions' => ['confirm', 'dismiss']];
    }

    public function act(User $user, string $id, string $action, array $changes = []): void
    {
        DB::transaction(function () use ($user, $id, $action, $changes) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $p = EventObject::where('user_id', $user->id)->where('type', 'task_proposal')->lockForUpdate()->findOrFail($id);
            $m = $p->metadata;
            if ($m['status'] !== 'pending') {
                return;
            }
            abort_unless(in_array($action, ['confirm', 'dismiss']), 422);
            if ($action === 'confirm') {
                $data = array_merge($m['task'], collect($changes)->except(['client_mutation_id'])->all());
                $task = app(TaskService::class)->create($user, $data);
                $m['task_id'] = $task['id'];
                $m['status'] = 'accepted';
            } else {
                $m['status'] = 'dismissed';
            }
            $m['reviewed_at'] = now()->toIso8601String();
            $p->update(['metadata' => $m]);
        });
    }
}
