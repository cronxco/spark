<?php

namespace App\Livewire;

use App\Models\EventObject;
use App\Services\EffectiveTimezoneResolver;
use App\Services\Knowledge\KnowledgeFeature;
use App\Services\Knowledge\ProjectService;
use App\Services\Knowledge\TaskService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpKernel\Exception\HttpException;

class KnowledgeTasks extends Component
{
    use WithPagination;

    public string $captureID;

    public ?string $review = null;

    public ?string $reminder = null;

    public int $position = 0;

    public array $selected = [];

    public ?string $bulkDate = null;

    public ?string $bulkProject = null;

    public array $taskHistory = [];

    public ?string $editingProject = null;

    public ?string $projectVersion = null;

    public ?string $projectContent = null;

    public ?string $projectTarget = null;

    public ?string $projectNext = null;

    public bool $projectHideTasks = true;

    public string $projectState = 'active';

    public string $filter = 'today';

    public string $search = '';

    public ?string $editing = null;

    public ?string $version = null;

    public string $title = '';

    public ?string $content = null;

    public string $lifecycle = 'persistent';

    public ?string $availability = null;

    public ?string $due = null;

    public ?string $project = null;

    public array $threads = [];

    public string $priority = 'normal';

    public ?string $recurrence = null;

    public string $projectTitle = '';

    public ?string $message = null;

    public function mount(?string $id = null, ?string $projectId = null): void
    {
        KnowledgeFeature::ensure(Auth::user(), 'reads');
        $this->captureID = (string) Str::uuid();
        $this->availability = app(EffectiveTimezoneResolver::class)->today(Auth::user())->toDateString();
        if ($id || request()->query('task')) {
            $this->edit($id ?: request()->query('task'));
        } if ($projectId) {
            $this->editProject($projectId);
        }
    }

    public function edit(string $id): void
    {
        $p = app(TaskService::class)->payload(app(TaskService::class)->find(Auth::user(), $id));
        $this->editing = $id;
        $this->version = $p['version'];
        $this->title = $p['title'];
        $this->content = $p['content'];
        $this->lifecycle = $p['lifecycle'];
        $this->availability = $p['availability_on'];
        $this->due = $p['due_on'];
        $this->project = $p['project_id'];
        $this->threads = $p['thread_ids'];
        $this->priority = $p['priority'];
        $this->recurrence = $p['recurrence'];
        $this->position = $p['position'];
        $this->review = $p['review_on'];
        $this->reminder = $p['remind_at'] ? CarbonImmutable::parse($p['remind_at'])->setTimezone($p['schedule_timezone'])->format('Y-m-d\\TH:i') : null;
    }

    public function save(): void
    {
        $s = app(TaskService::class);
        $data = ['title' => $this->title, 'content' => $this->content, 'lifecycle' => $this->lifecycle, 'availability_on' => $this->availability ?: null,
            'due_on' => $this->due ?: null, 'project_id' => $this->project ?: null, 'thread_ids' => $this->threads, 'priority' => $this->priority, 'recurrence' => $this->recurrence ?: null, 'position' => $this->position, 'review_on' => $this->review ?: null, 'remind_at' => $this->reminder ?: null];
        try {
            if ($this->editing) {
                $s->update(Auth::user(), $this->editing, $data, $this->version);
            } else {
                $s->create(Auth::user(), $data + ['client_mutation_id' => $this->captureID]);
            }
            $this->reset('editing', 'version', 'title', 'content', 'message', 'lifecycle', 'due', 'project', 'threads', 'priority', 'recurrence', 'position', 'review', 'reminder');
            $this->availability = app(EffectiveTimezoneResolver::class)->today(Auth::user())->toDateString();
            $this->captureID = (string) Str::uuid();
        } catch (HttpResponseException $e) {
            $this->message = 'This task changed. Open it again before saving.';
        }
    }

    public function act(string $id, string $action, string $version): void
    {
        try {
            app(TaskService::class)->act(Auth::user(), $id, $action, $version, ['availability_on' => app(EffectiveTimezoneResolver::class)->today(Auth::user())->toDateString()]);
        } catch (HttpResponseException $e) {
            $this->message = 'This task changed. Refresh before retrying.';
        } catch (HttpException $e) {
            $this->message = $e->getMessage();
        }
    }

    public function createProject(): void
    {
        try {
            app(ProjectService::class)->save(Auth::user(), ['title' => $this->projectTitle, 'content' => $this->projectContent,
                'target_on' => $this->projectTarget ?: null, 'next_action_task_id' => $this->projectNext ?: null, 'status' => $this->projectState,
                'hide_tasks' => $this->projectHideTasks], $this->editingProject, $this->projectVersion);
            $this->reset('projectTitle', 'projectContent', 'projectTarget', 'projectNext', 'editingProject', 'projectVersion');
        } catch (HttpResponseException $e) {
            $this->message = 'This project changed. Open it again before saving.';
        }
    }

    public function projectStatus(string $id, string $status, string $version): void
    {
        app(ProjectService::class)->save(Auth::user(), ['status' => $status], $id, $version);
    }

    public function editProject(string $id): void
    {
        $p = app(ProjectService::class)->payload(app(ProjectService::class)->query(Auth::user())->findOrFail($id));
        $this->editingProject = $id;
        $this->projectVersion = $p['version'];
        $this->projectTitle = $p['title'];
        $this->projectContent = $p['content'];
        $this->projectTarget = $p['target_on'];
        $this->projectNext = $p['next_action_task_id'];
        $this->projectState = $p['status'];
        $this->projectHideTasks = $p['hide_tasks'];
    }

    public function removeProject(string $id, string $version): void
    {
        app(ProjectService::class)->delete(Auth::user(), $id, $version);
    }

    public function showHistory(string $id): void
    {
        $this->taskHistory = app(TaskService::class)->history(Auth::user(), $id)->limit(50)->get()->map(fn ($e) => ['action' => $e->action, 'time' => $e->time?->toIso8601String()])->all();
    }

    public function bulk(string $action): void
    {
        foreach ($this->selected as $value) {
            [$id, $version] = explode('|', $value, 2);
            if ($action === 'defer' || $action === 'move') {
                try {
                    app(TaskService::class)->update(Auth::user(), $id, $action === 'defer' ? ['availability_on' => $this->bulkDate] : ['project_id' => $this->bulkProject ?: null], $version);
                } catch (HttpResponseException $e) {
                    $this->message = 'Some tasks changed. Refresh before retrying.';
                } catch (ValidationException $e) {
                    $this->message = collect($e->errors())->flatten()->implode(' ');
                }
            } else {
                $this->act($id, $action, $version);
            }
        }
        $this->selected = [];
    }

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $s = app(TaskService::class);
        $today = app(EffectiveTimezoneResolver::class)->today(Auth::user())->toDateString();
        $rows = $s->query(Auth::user(), $this->filter === 'bin')->when($this->search !== '', fn ($q) => $q->where('title', 'ilike', '%' . $this->search . '%'))->orderBy('blocks.id')->paginate(100);
        $tasks = $rows->getCollection()->map(fn ($b) => $s->payload($b, $today))->filter(fn ($p) => match ($this->filter) {
            'today' => $p['visible_on_date'], 'inbox' => $p['availability_on'] === null && $p['status'] === 'open',
            'history' => $p['status'] !== 'open' && $p['status'] !== 'deleted', 'bin' => $p['status'] === 'deleted', default => $p['status'] !== 'deleted',
        })->sortBy(fn ($p) => [$p['priority'] === 'important' ? 0 : 1, $p['position']]);

        return view('livewire.knowledge-tasks', ['tasks' => $tasks, 'rows' => $rows, 'projects' => app(ProjectService::class)->query(Auth::user())->get()->map(fn ($p) => app(ProjectService::class)->payload($p)),
            'topics' => EventObject::where('user_id', Auth::id())->where('concept', 'flint')->where('type', 'topic')->orderBy('title')->get()]);
    }
}
