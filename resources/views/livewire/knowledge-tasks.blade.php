<div class="space-y-6">
    <h1 class="text-2xl font-bold">Tasks & Projects</h1>
    @if($message)<div role="alert" class="alert alert-warning">{{ $message }}</div>@endif
    @foreach($errors->all() as $error)<p role="alert" class="text-error">{{ $error }}</p>@endforeach
    <form wire:submit="save" class="card bg-base-200 p-4 space-y-3">
        <h2 class="font-semibold">{{ $editing ? 'Edit task' : 'Capture a task' }}</h2>
        <input aria-label="Task title" wire:model="title" class="input input-bordered" placeholder="What needs doing?" required maxlength="255">
        <textarea aria-label="Details" wire:model="content" class="textarea textarea-bordered" placeholder="Details"></textarea>
        <div class="flex flex-wrap gap-3">
            <label>Duration <select wire:model="lifecycle" class="select select-bordered"><option value="day">Single day</option><option value="week">Up to seven days</option><option value="persistent">Until completed</option></select></label>
            <label>Available <input wire:model="availability" type="date" class="input input-bordered"></label>
            <label>Review <input wire:model="review" type="date" class="input input-bordered"></label>
            <label>Remind me <input type="datetime-local" wire:model="reminder" class="input input-bordered"></label>
            <label>Order <input type="number" min="0" wire:model="position" class="input input-bordered"></label>
            <label>Due <input wire:model="due" type="date" class="input input-bordered"></label>
            <label>Project <select wire:model="project" class="select select-bordered"><option value="">No project</option>@foreach($projects as $p)<option value="{{ $p['id'] }}">{{ $p['title'] }}</option>@endforeach</select></label>
            <label>Priority <select wire:model="priority" class="select select-bordered"><option value="normal">Normal</option><option value="important">Important</option></select></label>
            <label>Repeat <select wire:model="recurrence" class="select select-bordered"><option value="">Never</option><option value="daily">Daily</option><option value="weekly">Weekly</option><option value="monthly">Monthly</option></select></label>
            <label>Threads <select wire:model="threads" multiple class="select select-bordered">@foreach($topics as $topic)<option value="{{ $topic->id }}">{{ $topic->title }}</option>@endforeach</select></label>
        </div>
        <p class="text-sm">Leave Available empty for the inbox. Weekly tasks expire seven days after their original date.</p>
        <button class="btn btn-primary" wire:loading.attr="disabled">{{ $editing ? 'Save task' : 'Add task' }}</button>
    </form>
    <div class="flex gap-3"><select aria-label="Task view" wire:model.live="filter" class="select select-bordered"><option value="today">Today</option><option value="inbox">Inbox</option><option value="all">All tasks</option><option value="history">History</option><option value="bin">Deleted</option></select><input aria-label="Search tasks" wire:model.live.debounce.300ms="search" placeholder="Search tasks" class="input input-bordered"></div>
    <div class="flex gap-2"><button wire:click="bulk('complete')" class="btn btn-sm">Complete selected</button><button wire:click="bulk('cancel')" class="btn btn-sm" wire:confirm="Cancel selected tasks?">Cancel selected</button><button wire:click="bulk('reactivate')" class="btn btn-sm">Start selected today</button></div>
    <div class="flex flex-wrap gap-2">
        <label>Defer selected to <input type="date" wire:model="bulkDate" class="input input-bordered"></label><button class="btn btn-sm" wire:click="bulk('defer')">Defer selected</button>
        <select aria-label="Move selected to project" wire:model="bulkProject" class="select select-bordered"><option value="">No project</option>@foreach($projects as $p)<option value="{{ $p['id'] }}">{{ $p['title'] }}</option>@endforeach</select><button class="btn btn-sm" wire:click="bulk('move')">Move selected</button>
    </div>
    @forelse($tasks as $task)
        <article wire:key="task-{{ $task['id'] }}" class="card bg-base-200 p-4 space-y-2">
            <label class="flex gap-2"><input type="checkbox" wire:model="selected" value="{{ $task['id'] }}|{{ $task['version'] }}" aria-label="Select task"><span class="font-semibold">{{ $task['title'] }}</span></label><p>{{ $task['content'] }}</p>
            <p class="text-sm">{{ ucfirst($task['status']) }} · {{ $task['lifecycle'] }} @if($task['project_title']) · {{ $task['project_title'] }} @endif @if($task['overdue']) · Overdue @endif @if($task['carried_days']) · Carried {{ $task['carried_days'] }} days @endif</p>
            @if(in_array($task['sync']['status'], ['blocked','conflict','error']))<button class="btn btn-sm" wire:click="act('{{ $task['id'] }}','retry_sync','{{ $task['version'] }}')">Retry Outline sync</button>@endif
            @if($task['sync']['status'] !== 'synced')<p role="status">Outline: {{ $task['sync']['status'] }} @if($task['source_url'])<a class="link" href="{{ $task['source_url'] }}">Open source</a>@endif</p>@endif
            <div class="flex flex-wrap gap-2">
                @if($task['action'])<a class="btn btn-primary btn-sm" href="{{ $task['action']['url'] }}">{{ $task['action']['kind'] === 'integration_reauth' ? 'Reconnect' : 'Review connection' }}</a>
                @elseif($task['status'] === 'open')<button class="btn btn-sm" wire:click="act('{{ $task['id'] }}','complete', '{{ $task['version'] }}')">Complete</button>@endif
                @if($task['status'] !== 'deleted')<button class="btn btn-sm" wire:click="edit('{{ $task['id'] }}')">Edit</button>@endif
                @if($task['status'] === 'completed')<button class="btn btn-sm" wire:click="act('{{ $task['id'] }}','reopen','{{ $task['version'] }}')">Reopen</button>@endif
                @if(in_array($task['status'], ['expired','cancelled','completed']))<button class="btn btn-sm" wire:click="act('{{ $task['id'] }}','reactivate','{{ $task['version'] }}')">Reactivate today</button>@endif
                <button class="btn btn-ghost btn-sm" wire:click="act('{{ $task['id'] }}','cancel','{{ $task['version'] }}')" wire:confirm="Cancel this task?">Cancel</button>
                <button class="btn btn-sm" wire:click="showHistory('{{ $task['id'] }}')">Activity</button>
                @if($task['status'] === 'deleted')<button class="btn btn-sm" wire:click="act('{{ $task['id'] }}','restore','{{ $task['version'] }}')">Restore</button>
                @else<button class="btn btn-ghost btn-sm" wire:confirm="Move this task to Deleted?" wire:click="act('{{ $task['id'] }}','delete','{{ $task['version'] }}')">Delete</button>@endif
            </div>
        </article>
    @empty <p>No tasks in this view.</p> @endforelse
    {{ $rows->links() }}
    @if($taskHistory)<section><h2 class="font-semibold">Task activity</h2>@foreach($taskHistory as $change)<p>{{ str_replace('_',' ', $change['action']) }} · {{ $change['time'] }}</p>@endforeach</section>@endif
    <h2 class="text-xl font-bold">Projects</h2>
    <form wire:submit="createProject" class="card bg-base-200 p-4 space-y-3">
        <input aria-label="Project title" wire:model="projectTitle" class="input input-bordered" placeholder="Project name" required>
        <textarea aria-label="Project outcome" wire:model="projectContent" class="textarea textarea-bordered" placeholder="What does success look like?"></textarea>
        <label>Target date <input type="date" wire:model="projectTarget" class="input input-bordered"></label>
        <label>Status <select wire:model="projectState" class="select select-bordered">@foreach(['active','paused','completed','archived'] as $status)<option value="{{ $status }}">{{ ucfirst($status) }}</option>@endforeach</select></label>
        <label><input type="checkbox" wire:model="projectHideTasks"> Hide tasks when paused</label>
        @if($editingProject)<label>Next action <select wire:model="projectNext" class="select select-bordered"><option value="">Suggest automatically</option>@foreach($tasks->where('project_id',$editingProject)->where('status','open') as $candidate)<option value="{{ $candidate['id'] }}">{{ $candidate['title'] }}</option>@endforeach</select></label>@endif
        <p>Completing or archiving the project keeps its unfinished tasks active. Cancel or move them separately if needed.</p>
        <button class="btn btn-primary">{{ $editingProject ? 'Save project' : 'Create project' }}</button>
    </form>
    @foreach($projects as $p)
    <article class="card bg-base-200 p-4"><h3>{{ $p['title'] }} · {{ $p['status'] }}</h3><p>{{ $p['content'] }}</p><p>{{ $p['progress']['completed'] }} / {{ $p['progress']['total'] }} completed</p>
        <div class="flex gap-2"><button class="btn btn-sm" wire:click="editProject('{{ $p['id'] }}')">Edit project</button>
            <button class="btn btn-sm" wire:confirm="Remove this project? Its tasks will remain without a project." wire:click="removeProject('{{ $p['id'] }}','{{ $p['version'] }}')">Remove</button>
        </div>
    </article>
    @endforeach
</div>
