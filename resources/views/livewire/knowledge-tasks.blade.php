<div class="space-y-6">
    <x-header title="Tasks & Projects" separator>
        <x-slot:middle class="!justify-end">
            @if ($section === 'tasks')
                <x-input aria-label="Search tasks" placeholder="Search tasks..." wire:model.live.debounce.300ms="search" icon="fas.magnifying-glass" clearable />
            @endif
        </x-slot:middle>
        <x-slot:actions>
            @if ($section === 'tasks')
                <x-button label="New task" icon="fas.plus" wire:click="newTask" class="btn-primary btn-sm" />
            @else
                <x-button label="New project" icon="fas.plus" wire:click="newProject" class="btn-primary btn-sm" />
            @endif
        </x-slot:actions>
    </x-header>

    @if ($message)
        <div role="alert" class="alert alert-warning">
            <x-icon name="fas.triangle-exclamation" class="w-5 h-5" />
            <span>{{ $message }}</span>
        </div>
    @endif

    <div role="tablist" class="tabs tabs-boxed w-fit">
        <button role="tab" type="button" wire:click="$set('section', 'tasks')" class="tab {{ $section === 'tasks' ? 'tab-active' : '' }}">Tasks</button>
        <button role="tab" type="button" wire:click="$set('section', 'projects')" class="tab {{ $section === 'projects' ? 'tab-active' : '' }}">Projects</button>
    </div>

    @if ($section === 'tasks')
        <div class="flex flex-wrap gap-2">
            @foreach (['today' => 'Today', 'inbox' => 'Inbox', 'all' => 'All', 'history' => 'History', 'bin' => 'Deleted'] as $key => $label)
                <x-button :label="$label" wire:click="$set('filter', '{{ $key }}')" class="btn-sm {{ $filter === $key ? 'btn-primary' : 'btn-ghost' }}" />
            @endforeach
        </div>

        @if (count($selected) > 0)
            <div class="card bg-base-200 sticky top-2 z-10 shadow-md">
                <div class="card-body p-3 flex-row flex-wrap items-center gap-2">
                    <span class="font-semibold mr-2">{{ count($selected) }} selected</span>
                    <x-button label="Complete" icon="fas.circle-check" wire:click="bulk('complete')" class="btn-sm" />
                    <x-button label="Start today" icon="fas.play" wire:click="bulk('reactivate')" class="btn-sm" />
                    <x-dropdown>
                        <x-slot:trigger>
                            <x-button label="Defer" icon="fas.calendar" class="btn-sm" />
                        </x-slot:trigger>
                        <div class="p-3 space-y-2 w-64">
                            <x-input label="Defer to" type="date" wire:model="bulkDate" />
                            <x-button label="Defer" wire:click="bulk('defer')" class="btn-primary btn-sm w-full" />
                        </div>
                    </x-dropdown>
                    <x-dropdown>
                        <x-slot:trigger>
                            <x-button label="Move" icon="fas.arrow-right" class="btn-sm" />
                        </x-slot:trigger>
                        <div class="p-3 space-y-2 w-64">
                            <x-select label="Project" :options="$projects->map(fn ($p) => ['id' => $p['id'], 'name' => $p['title']])->all()" wire:model="bulkProject" placeholder="No project" />
                            <x-button label="Move" wire:click="bulk('move')" class="btn-primary btn-sm w-full" />
                        </div>
                    </x-dropdown>
                    <x-button label="Cancel tasks" icon="fas.xmark" wire:click="bulk('cancel')" wire:confirm="Cancel selected tasks?" class="btn-sm btn-ghost ml-auto" />
                </div>
            </div>
        @endif

        <div class="space-y-3">
            @forelse ($tasks as $task)
                @php
                    $isOpen = $task['status'] === 'open';
                    $v = $task['version'];
                    $id = $task['id'];
                @endphp
                <article wire:key="task-{{ $id }}" class="card bg-base-200">
                    <div class="card-body p-3 flex-row items-start gap-3">
                        <input type="checkbox" class="checkbox checkbox-sm mt-1" wire:model.live="selected" value="{{ $id }}|{{ $v }}" aria-label="Select {{ $task['title'] }}">
                        <div class="min-w-0 flex-1 space-y-1">
                            <div class="flex items-center gap-2">
                                @if ($task['priority'] === 'important')
                                    <x-icon name="fas.star" class="w-4 h-4 text-warning" />
                                @endif
                                <span class="font-semibold break-words {{ $isOpen ? '' : 'text-base-content/60' }} {{ $task['status'] === 'completed' ? 'line-through' : '' }}">{{ $task['title'] }}</span>
                            </div>
                            @if ($task['content'])
                                <p class="text-sm text-base-content/70 line-clamp-2">{{ $task['content'] }}</p>
                            @endif
                            <div class="flex flex-wrap items-center gap-1.5 text-xs">
                                @if (! $isOpen)
                                    <span class="badge badge-ghost">{{ ucfirst($task['status']) }}</span>
                                @endif
                                @if ($task['project_title'])
                                    <span class="badge badge-outline gap-1"><x-icon name="fas.layer-group" class="w-3 h-3" />{{ $task['project_title'] }}</span>
                                @endif
                                @if ($task['overdue'])
                                    <span class="badge badge-warning gap-1"><x-icon name="fas.triangle-exclamation" class="w-3 h-3" />Overdue</span>
                                @elseif ($task['due_on'])
                                    <span class="badge badge-ghost gap-1"><x-icon name="fas.calendar" class="w-3 h-3" />Due {{ \Carbon\Carbon::parse($task['due_on'])->format('j M') }}</span>
                                @endif
                                @if ($task['carried_days'])
                                    <span class="badge badge-ghost">Carried {{ $task['carried_days'] }}d</span>
                                @endif
                                @if ($task['recurrence'])
                                    <span class="badge badge-ghost gap-1"><x-icon name="fas.rotate" class="w-3 h-3" />{{ ucfirst($task['recurrence']) }}</span>
                                @endif
                                @if ($task['sync']['status'] !== 'synced')
                                    <span class="badge badge-warning badge-outline">Outline {{ $task['sync']['status'] }}</span>
                                    @if ($task['source_url'])
                                        <a class="link link-hover" href="{{ $task['source_url'] }}">Open source</a>
                                    @endif
                                @endif
                            </div>
                        </div>
                        <div class="flex items-center gap-1">
                            @if ($task['action'])
                                <a class="btn btn-primary btn-sm" href="{{ $task['action']['url'] }}">{{ $task['action']['kind'] === 'integration_reauth' ? 'Reconnect' : 'Review connection' }}</a>
                            @elseif ($isOpen)
                                <x-button icon="fas.check" wire:click="act('{{ $id }}', 'complete', '{{ $v }}')" class="btn-ghost btn-sm" tooltip="Complete" aria-label="Complete task" />
                            @elseif ($task['status'] === 'completed')
                                <x-button icon="fas.rotate" wire:click="act('{{ $id }}', 'reopen', '{{ $v }}')" class="btn-ghost btn-sm" tooltip="Reopen" aria-label="Reopen task" />
                            @elseif ($task['status'] === 'deleted')
                                <x-button icon="fas.rotate" wire:click="act('{{ $id }}', 'restore', '{{ $v }}')" class="btn-ghost btn-sm" tooltip="Restore" aria-label="Restore task" />
                            @endif
                            <x-dropdown position="dropdown-end">
                                <x-slot:trigger>
                                    <x-button icon="fas.ellipsis-vertical" class="btn-ghost btn-sm" aria-label="More actions" />
                                </x-slot:trigger>
                                @if ($task['status'] !== 'deleted')
                                    <x-menu-item title="Edit" icon="fas.pen" wire:click="edit('{{ $id }}')" />
                                @endif
                                <x-menu-item title="Activity" icon="fas.clock" wire:click="showHistory('{{ $id }}')" />
                                @if (in_array($task['status'], ['expired', 'cancelled', 'completed']))
                                    <x-menu-item title="Start today" icon="fas.play" wire:click="act('{{ $id }}', 'reactivate', '{{ $v }}')" />
                                @endif
                                @if (in_array($task['sync']['status'], ['blocked', 'conflict', 'error']))
                                    <x-menu-item title="Retry Outline sync" icon="fas.rotate" wire:click="act('{{ $id }}', 'retry_sync', '{{ $v }}')" />
                                @endif
                                @if ($isOpen)
                                    <x-menu-item title="Cancel task" icon="fas.xmark" wire:click="act('{{ $id }}', 'cancel', '{{ $v }}')" wire:confirm="Cancel this task?" />
                                @endif
                                @if ($task['status'] !== 'deleted')
                                    <x-menu-item title="Delete" icon="fas.trash" wire:click="act('{{ $id }}', 'delete', '{{ $v }}')" wire:confirm="Move this task to Deleted?" />
                                @endif
                            </x-dropdown>
                        </div>
                    </div>
                </article>
            @empty
                <div class="card bg-base-200">
                    <div class="card-body items-center text-center text-base-content/60">
                        <x-icon name="fas.inbox" class="w-10 h-10 text-base-content/30" />
                        <p>{{ $search !== '' ? 'No tasks match your search.' : 'No tasks in this view.' }}</p>
                    </div>
                </div>
            @endforelse
        </div>

        {{ $rows->links() }}

        @if ($taskHistory)
            <section class="card bg-base-200">
                <div class="card-body p-4 gap-2">
                    <div class="flex items-center justify-between">
                        <h2 class="font-semibold">Task activity</h2>
                        <x-button icon="fas.xmark" wire:click="$set('taskHistory', [])" class="btn-ghost btn-xs" aria-label="Close activity" />
                    </div>
                    @foreach ($taskHistory as $change)
                        <p class="text-sm">{{ ucfirst(str_replace('_', ' ', $change['action'])) }} <span class="text-base-content/60">· <x-user-time :time="$change['time']" format="j M Y, H:i" /></span></p>
                    @endforeach
                </div>
            </section>
        @endif
    @else
        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            @forelse ($projects as $p)
                <article wire:key="project-{{ $p['id'] }}" class="card bg-base-200">
                    <div class="card-body p-4 gap-2">
                        <div class="flex items-start justify-between gap-2">
                            <h3 class="font-semibold break-words">{{ $p['title'] }}</h3>
                            <span class="badge {{ $p['status'] === 'active' ? 'badge-primary' : 'badge-ghost' }}">{{ ucfirst($p['status']) }}</span>
                        </div>
                        @if ($p['content'])
                            <p class="text-sm text-base-content/70 line-clamp-3">{{ $p['content'] }}</p>
                        @endif
                        @if ($p['progress']['total'] > 0)
                            <progress class="progress progress-primary w-full" value="{{ $p['progress']['completed'] }}" max="{{ $p['progress']['total'] }}"></progress>
                            <p class="text-xs text-base-content/60">{{ $p['progress']['completed'] }} of {{ $p['progress']['total'] }} completed</p>
                        @endif
                        @if ($p['target_on'])
                            <p class="text-xs text-base-content/60">Target {{ \Carbon\Carbon::parse($p['target_on'])->format('j M Y') }}</p>
                        @endif
                        <div class="card-actions justify-end">
                            <x-button label="Edit" icon="fas.pen" wire:click="editProject('{{ $p['id'] }}')" class="btn-ghost btn-sm" />
                            <x-button icon="fas.trash" wire:click="removeProject('{{ $p['id'] }}', '{{ $p['version'] }}')" wire:confirm="Remove this project? Its tasks will remain without a project." class="btn-ghost btn-sm" aria-label="Remove project" />
                        </div>
                    </div>
                </article>
            @empty
                <div class="card bg-base-200 md:col-span-2 xl:col-span-3">
                    <div class="card-body items-center text-center text-base-content/60">
                        <x-icon name="fas.layer-group" class="w-10 h-10 text-base-content/30" />
                        <p>No projects yet.</p>
                    </div>
                </div>
            @endforelse
        </div>
    @endif

    <x-modal wire:model="showTaskForm" :title="$editing ? 'Edit task' : 'New task'" class="backdrop-blur" separator>
        <x-form wire:submit="save" no-separator>
            @foreach ($errors->all() as $error)
                <p role="alert" class="text-error text-sm">{{ $error }}</p>
            @endforeach
            <x-input label="Task" wire:model="title" placeholder="What needs doing?" maxlength="255" required />
            <x-textarea label="Details" wire:model="content" rows="3" />
            <div class="grid gap-3 sm:grid-cols-2">
                <x-select label="Duration" wire:model="lifecycle" :options="[['id' => 'day', 'name' => 'Single day'], ['id' => 'week', 'name' => 'Up to seven days'], ['id' => 'persistent', 'name' => 'Until completed']]" />
                <x-input label="Available from" type="date" wire:model="availability" hint="Empty puts it in the inbox" />
                <x-input label="Due" type="date" wire:model="due" />
                <x-select label="Project" wire:model="project" :options="$projects->map(fn ($p) => ['id' => $p['id'], 'name' => $p['title']])->all()" placeholder="No project" />
                <x-select label="Priority" wire:model="priority" :options="[['id' => 'normal', 'name' => 'Normal'], ['id' => 'important', 'name' => 'Important']]" />
                <x-select label="Repeat" wire:model="recurrence" :options="[['id' => 'daily', 'name' => 'Daily'], ['id' => 'weekly', 'name' => 'Weekly'], ['id' => 'monthly', 'name' => 'Monthly']]" placeholder="Never" />
            </div>
            @if ($topics->isNotEmpty())
                <fieldset class="space-y-2">
                    <legend class="text-sm font-medium">Threads</legend>
                    <div class="grid max-h-40 gap-1 overflow-y-auto rounded-lg border border-base-300 p-2 sm:grid-cols-2">
                        @foreach ($topics as $topic)
                            <label wire:key="thread-{{ $topic->id }}" class="flex cursor-pointer items-center gap-2 text-sm">
                                <input type="checkbox" class="checkbox checkbox-sm" wire:model="threads" value="{{ $topic->id }}">
                                <span class="truncate">{{ $topic->title }}</span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>
            @endif
            <details class="collapse collapse-arrow bg-base-200" @if ($review || $reminder || $position) open @endif>
                <summary class="collapse-title text-sm font-medium">More options</summary>
                <div class="collapse-content grid gap-3 sm:grid-cols-3">
                    <x-input label="Review on" type="date" wire:model="review" />
                    <x-input label="Remind me" type="datetime-local" wire:model="reminder" />
                    <x-input label="Order" type="number" min="0" wire:model="position" />
                </div>
            </details>
            <x-slot:actions>
                <x-button label="Cancel" wire:click="$set('showTaskForm', false)" />
                <x-button label="{{ $editing ? 'Save task' : 'Add task' }}" type="submit" class="btn-primary" spinner="save" />
            </x-slot:actions>
        </x-form>
    </x-modal>

    <x-modal wire:model="showProjectForm" :title="$editingProject ? 'Edit project' : 'New project'" class="backdrop-blur" separator>
        <x-form wire:submit="createProject" no-separator>
            @foreach ($errors->all() as $error)
                <p role="alert" class="text-error text-sm">{{ $error }}</p>
            @endforeach
            <x-input label="Project" wire:model="projectTitle" placeholder="Project name" required />
            <x-textarea label="Outcome" wire:model="projectContent" placeholder="What does success look like?" rows="3" />
            <div class="grid gap-3 sm:grid-cols-2">
                <x-input label="Target date" type="date" wire:model="projectTarget" />
                <x-select label="Status" wire:model="projectState" :options="[['id' => 'active', 'name' => 'Active'], ['id' => 'paused', 'name' => 'Paused'], ['id' => 'completed', 'name' => 'Completed'], ['id' => 'archived', 'name' => 'Archived']]" />
            </div>
            @if ($editingProject)
                <x-select label="Next action" wire:model="projectNext" :options="$tasks->where('project_id', $editingProject)->where('status', 'open')->map(fn ($t) => ['id' => $t['id'], 'name' => $t['title']])->values()->all()" placeholder="Suggest automatically" />
            @endif
            <x-toggle label="Hide tasks while paused" wire:model="projectHideTasks" />
            @if (in_array($projectState, ['completed', 'archived']))
                <p class="text-sm text-base-content/70">Unfinished tasks stay active. Cancel or move them separately.</p>
            @endif
            <x-slot:actions>
                <x-button label="Cancel" wire:click="$set('showProjectForm', false)" />
                <x-button label="{{ $editingProject ? 'Save project' : 'Create project' }}" type="submit" class="btn-primary" spinner="createProject" />
            </x-slot:actions>
        </x-form>
    </x-modal>
</div>
