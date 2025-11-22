

<?php
use App\Integrations\PluginRegistry;
use App\Models\Integration;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new class extends Component {
    use Toast;

    public Integration $integration;
    public array $schema = [];
    public array $configuration = [];
    public string $name = '';

    public function mount(Integration $integration): void
    {
        // Ensure user owns this integration
        if ((string) $integration->user_id !== (string) Auth::id()) {
            abort(403);
        }

        $this->integration = $integration;
        $this->name = $integration->name ?: $integration->service;

        $pluginClass = PluginRegistry::getPlugin($integration->service);
        if (!$pluginClass) {
            abort(404);
        }

        // Prefer instance-type specific schema when available
        $instanceTypes = method_exists($pluginClass, 'getInstanceTypes') ? $pluginClass::getInstanceTypes() : [];
        if (!empty($instanceTypes) && isset($instanceTypes[$integration->instance_type]['schema'])) {
            $this->schema = $instanceTypes[$integration->instance_type]['schema'];
        } else {
            $this->schema = $pluginClass::getConfigurationSchema();
        }
        $this->configuration = $integration->configuration ?? [];

        // Ensure update_frequency_minutes is in configuration if it exists in schema
        if (isset($this->schema['update_frequency_minutes'])) {
            $this->configuration['update_frequency_minutes'] = $integration->getUpdateFrequencyMinutes();
        }

        // Ensure array fields are properly initialized as arrays
        foreach ($this->schema as $field => $config) {
            if ($config['type'] === 'array' && !isset($this->configuration[$field])) {
                $this->configuration[$field] = [];
            }
        }

        // Coerce booleans for toggles so UI reflects saved values
        $this->configuration['use_schedule'] = (bool) (int) ($this->configuration['use_schedule'] ?? 0);
        $this->configuration['paused'] = (bool) (int) ($this->configuration['paused'] ?? 0);
    }

    public function updateName(): void
    {
        if (empty(trim($this->name))) {
            $this->error('Integration name cannot be empty.');
            return;
        }

        try {
            $this->integration->update(['name' => trim($this->name)]);
            $this->success('Integration name updated successfully!');
        } catch (\Exception $e) {
            $this->error('Failed to update integration name. Please try again.');
        }
    }

    public function toggleCheckbox(string $field, string $value): void
    {
        if (!isset($this->configuration[$field])) {
            $this->configuration[$field] = [];
        }

        $currentValues = $this->configuration[$field];

        if (in_array($value, $currentValues)) {
            // Remove value if already present
            $this->configuration[$field] = array_values(array_filter($currentValues, fn($v) => $v !== $value));
        } else {
            // Add value if not present
            $this->configuration[$field][] = $value;
        }
    }

    public function updateConfiguration(): void
    {
        try {
            // Pre-normalize schedule times input for validation
            if (isset($this->configuration['schedule_times']) && is_string($this->configuration['schedule_times'])) {
                $parts = preg_split('/[\s,]+/', $this->configuration['schedule_times']) ?: [];
                $this->configuration['schedule_times'] = array_values(array_filter(array_map('trim', $parts)));
            }

            // Pre-validate task_payload JSON if provided as string
            if (isset($this->configuration['task_payload']) && is_string($this->configuration['task_payload']) && $this->configuration['task_payload'] !== '') {
                $decoded = json_decode($this->configuration['task_payload'], true);
                if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                    $this->addError('configuration.task_payload', 'Payload must be valid JSON object.');
                    return;
                }
            }

            // Pre-normalize any schema-declared array fields that may be comma/space separated strings
            foreach ($this->schema as $field => $config) {
                if (($config['type'] ?? null) === 'array' && isset($this->configuration[$field]) && is_string($this->configuration[$field])) {
                    $parts = preg_split('/[\s,]+/', (string) $this->configuration[$field]) ?: [];
                    $this->configuration[$field] = array_values(array_filter(array_map('trim', $parts)));
                }
            }

            // Validate according to rules (includes conditional requirements)
            $this->validate();

            // Validate timezone if provided/required
            if (!empty($this->configuration['schedule_timezone'])) {
                $validTz = in_array($this->configuration['schedule_timezone'], DateTimeZone::listIdentifiers(), true);
                if (! $validTz) {
                    $this->addError('configuration.schedule_timezone', 'Invalid timezone.');
                    return;
                }
            }

            $updateData = ['configuration' => []];

            // Process the current configuration state
            foreach ($this->configuration as $field => $value) {
                // Handle array fields that might come as strings
                if (isset($this->schema[$field]) && $this->schema[$field]['type'] === 'array' && is_string($value)) {
                    $updateData['configuration'][$field] = array_filter(array_map('trim', explode(',', $value)));
                } else {
                    $updateData['configuration'][$field] = $value;
                }
            }

            // Ensure schema-free arrays like repositories/events (GitHub) are normalized when set as comma-separated
            foreach (['repositories', 'events'] as $arrayField) {
                if (isset($updateData['configuration'][$arrayField]) && is_string($updateData['configuration'][$arrayField])) {
                    $parts = preg_split('/[\s,]+/', (string) $updateData['configuration'][$arrayField]) ?: [];
                    $updateData['configuration'][$arrayField] = array_values(array_filter(array_map('trim', $parts)));
                }
            }

            // Normalize scheduling booleans
            $updateData['configuration']['use_schedule'] = (bool) ($updateData['configuration']['use_schedule'] ?? ($this->configuration['use_schedule'] ?? false));
            $updateData['configuration']['paused'] = (bool) ($updateData['configuration']['paused'] ?? ($this->configuration['paused'] ?? false));

            // Normalize schedule times input: accept comma or newline separated strings
            if (!empty($updateData['configuration']['schedule_times']) && is_string($updateData['configuration']['schedule_times'])) {
                $parts = preg_split('/[\s,]+/', $updateData['configuration']['schedule_times']) ?: [];
                $updateData['configuration']['schedule_times'] = array_values(array_filter(array_map('trim', $parts)));
            }

            // Parse task_payload if provided as JSON string
            if (isset($updateData['configuration']['task_payload']) && is_string($updateData['configuration']['task_payload'])) {
                $decoded = json_decode($updateData['configuration']['task_payload'], true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $updateData['configuration']['task_payload'] = $decoded;
                }
            }

            $this->integration->update($updateData);

            $this->success('Integration configured successfully!');
            $this->redirect(route('integrations.index'));
        } catch (\Exception $e) {
            $this->error('Failed to update configuration. Please try again.');
        }
    }

    protected function buildValidationRules(): array
    {
        $rules = [];

        foreach ($this->schema as $field => $config) {
            $fieldRules = [];

            if ($config['required'] ?? false) {
                $fieldRules[] = 'required';
            } else {
                $fieldRules[] = 'nullable';
            }

            switch ($config['type']) {
                case 'array':
                    $fieldRules[] = 'array';
                    break;
                case 'string':
                    $fieldRules[] = 'string';
                    break;
                case 'integer':
                    $fieldRules[] = 'integer';
                    if (isset($config['min'])) {
                        $fieldRules[] = "min:{$config['min']}";
                    }
                    break;
            }

            $rules["configuration.{$field}"] = $fieldRules;
        }

        $useSchedule = (bool) ($this->configuration['use_schedule'] ?? false);
        $rules['configuration.use_schedule'] = ['nullable'];
        $rules['configuration.paused'] = ['nullable'];
        $rules['configuration.schedule_timezone'] = [$useSchedule ? 'required' : 'nullable', 'string'];
        $rules['configuration.schedule_times'] = [$useSchedule ? 'required' : 'nullable', 'array'];
        $rules['configuration.schedule_times.*'] = ['regex:/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/'];
        $taskMode = (string) ($this->configuration['task_mode'] ?? '');
        $rules['configuration.task_mode'] = ['nullable', 'string', 'in:artisan,job'];
        $rules['configuration.task_command'] = [$taskMode === 'artisan' ? 'required' : 'nullable', 'string'];
        $rules['configuration.task_job_class'] = [$taskMode === 'job' ? 'required' : 'nullable', 'string'];
        $rules['configuration.task_queue'] = ['nullable', 'string'];
        $rules['configuration.task_payload'] = ['nullable'];

        return $rules;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            ...$this->buildValidationRules(),
        ];
    }
}; ?>

<div>
    <x-header title="Configure Integration" subtitle="{{ $integration->name ?: $integration->service }}" separator>
        <x-slot:actions>
            <!-- Desktop: Full buttons -->
            <div class="hidden sm:flex gap-2">
                <x-button
                    label="{{ __('Cancel') }}"
                    link="{{ route('integrations.index') }}"
                    class="btn-outline"
                />
                <x-button
                    label="{{ __('Save Configuration') }}"
                    wire:click="updateConfiguration"
                    class="btn-primary"
                />
            </div>

            <!-- Mobile: Dropdown -->
            <div class="sm:hidden">
                <x-dropdown>
                    <x-slot:trigger>
                        <x-button class="btn-ghost btn-sm">
                            <x-icon name="fas-ellipsis-vertical" class="w-5 h-5" />
                        </x-button>
                    </x-slot:trigger>
                    <x-menu-item title="Save" icon="fas-check" wire:click="updateConfiguration" />
                    <x-menu-item title="Cancel" icon="fas-xmark" link="{{ route('integrations.index') }}" />
                </x-dropdown>
            </div>
        </x-slot:actions>
    </x-header>

    <div class="max-w-4xl mx-auto space-y-4 lg:space-y-6">
        <!-- Integration Name Section -->
        <div class="card bg-base-200 shadow">
            <div class="card-body">
                <h3 class="text-lg font-semibold mb-2">{{ __('Integration Name') }}</h3>
                <p class="text-sm text-base-content/70 mb-4">{{ __('Give this integration instance a custom name') }}</p>
                <div class="flex flex-col sm:flex-row gap-2">
                    <x-input
                        wire:model.live.debounce.500ms="name"
                        placeholder="Enter integration name"
                        class="flex-1"
                    />
                    <x-button
                        label="{{ __('Update Name') }}"
                        wire:click="updateName"
                        class="btn-outline"
                    />
                </div>
            </div>
        </div>

        <!-- Configuration Form -->
        <form wire:submit="updateConfiguration" class="space-y-4 lg:space-y-6">
            <!-- Scheduling & Pause -->
            <div class="card bg-base-200 shadow">
                <div class="card-body">
                    <h3 class="text-lg font-semibold mb-2">{{ __('Scheduling') }}</h3>
                    <p class="text-sm text-base-content/70 mb-4">{{ __('Enable a fixed daily schedule or use frequency-based updates') }}</p>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="flex items-center justify-between p-3 bg-base-100 rounded-lg">
                            <div>
                                <div class="font-medium text-sm">{{ __('Use Schedule') }}</div>
                                <div class="text-xs text-base-content/60">{{ __('Use fixed daily schedule instead of frequency') }}</div>
                            </div>
                            <input type="checkbox" wire:model="configuration.use_schedule" class="toggle toggle-primary" />
                        </div>
                        <div class="flex items-center justify-between p-3 bg-base-100 rounded-lg">
                            <div>
                                <div class="font-medium text-sm">{{ __('Paused') }}</div>
                                <div class="text-xs text-base-content/60">{{ __('Temporarily disable this integration') }}</div>
                            </div>
                            <input type="checkbox" wire:model="configuration.paused" class="toggle toggle-primary" />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
                        <div class="form-control">
                            <label class="label">
                                <span class="label-text">{{ __('Schedule Times') }}</span>
                            </label>
                            <input
                                type="text"
                                wire:model="configuration.schedule_times"
                                placeholder="04:10 10:10 16:10 22:10"
                                class="input input-bordered w-full"
                            />
                            <label class="label">
                                <span class="label-text-alt text-base-content/70">HH:mm, comma or space separated</span>
                            </label>
                        </div>
                        <div class="form-control">
                            <label class="label">
                                <span class="label-text">{{ __('Schedule Timezone') }}</span>
                            </label>
                            <input
                                type="text"
                                wire:model="configuration.schedule_timezone"
                                placeholder="UTC"
                                class="input input-bordered w-full"
                            />
                        </div>
                        <div class="form-control">
                            <label class="label">
                                <span class="label-text">{{ __('Fallback Frequency (minutes)') }}</span>
                            </label>
                            <input
                                type="number"
                                wire:model="configuration.update_frequency_minutes"
                                placeholder="60"
                                min="1"
                                class="input input-bordered w-full"
                            />
                        </div>
                    </div>
                </div>
            </div>

            @foreach ($schema as $field => $config)
                @php
                    $pluginClass = \App\Integrations\PluginRegistry::getPlugin($integration->service);
                    $isWebhook = $pluginClass && $pluginClass::getServiceType() === 'webhook';
                    $shouldHideField = $isWebhook && $field === 'update_frequency_minutes';
                @endphp
                @if (!$shouldHideField)
                <div class="card bg-base-200 shadow">
                    <div class="card-body">
                        <h3 class="text-lg font-semibold mb-2">{{ $config['label'] }}</h3>
                        @if (isset($config['description']))
                            <p class="text-sm text-base-content/70 mb-4">{{ $config['description'] }}</p>
                        @endif

                        <div class="space-y-3">
                            @if ($config['type'] === 'boolean')
                                <div class="flex items-center justify-between p-3 bg-base-100 rounded-lg">
                                    <div>
                                        <div class="font-medium text-sm">{{ $config['label'] }}</div>
                                        @if (isset($config['description']))
                                            <div class="text-xs text-base-content/60">{{ $config['description'] }}</div>
                                        @endif
                                    </div>
                                    <input type="checkbox" wire:model="configuration.{{ $field }}" class="toggle toggle-primary" />
                                </div>
                            @elseif ($config['type'] === 'array' && isset($config['options']))
                                @foreach ($config['options'] as $value => $label)
                                    <div class="form-control">
                                        <label class="label cursor-pointer justify-start gap-2">
                                            <input
                                                type="checkbox"
                                                id="{{ $field }}_{{ $value }}"
                                                wire:click="toggleCheckbox('{{ $field }}', '{{ $value }}')"
                                                @checked(in_array($value, $configuration[$field] ?? []))
                                                class="checkbox"
                                            />
                                            <span class="label-text">{{ $label }}</span>
                                        </label>
                                    </div>
                                @endforeach
                            @elseif ($config['type'] === 'array')
                                <div class="form-control">
                                    <label class="label">
                                        <span class="label-text">Values</span>
                                    </label>
                                    <textarea
                                        wire:model="configuration.{{ $field }}"
                                        rows="3"
                                        placeholder="Enter values separated by commas"
                                        class="textarea textarea-bordered w-full"
                                    ></textarea>
                                </div>
                            @elseif ($config['type'] === 'string' && ($config['options'] ?? null))
                                <div class="form-control">
                                    <label class="label">
                                        <span class="label-text">Select option</span>
                                    </label>
                                    <select class="select select-bordered w-full" wire:model="configuration.{{ $field }}">
                                        @foreach (($config['options'] ?? []) as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @elseif ($config['type'] === 'string')
                                <div class="form-control">
                                    <label class="label">
                                        <span class="label-text">Value</span>
                                    </label>
                                    <input
                                        type="text"
                                        wire:model="configuration.{{ $field }}"
                                        placeholder="Enter {{ strtolower($config['label']) }}"
                                        class="input input-bordered w-full"
                                    />
                                </div>
                            @elseif ($config['type'] === 'integer')
                                <div class="form-control">
                                    <label class="label">
                                        <span class="label-text">Value</span>
                                    </label>
                                    <input
                                        type="number"
                                        wire:model="configuration.{{ $field }}"
                                        min="{{ $config['min'] ?? 1 }}"
                                        placeholder="Enter {{ strtolower($config['label']) }}"
                                        class="input input-bordered w-full"
                                    />
                                </div>
                            @endif

                            @error("configuration.{$field}")
                                <p class="text-sm text-error mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </div>
                @endif
            @endforeach

            <!-- Mobile: Show action buttons at bottom -->
            <div class="sm:hidden flex flex-col gap-2">
                <x-button
                    label="{{ __('Save Configuration') }}"
                    type="submit"
                    class="btn-primary w-full"
                />
                <x-button
                    label="{{ __('Cancel') }}"
                    link="{{ route('integrations.index') }}"
                    class="btn-outline w-full"
                />
            </div>
        </form>
    </div>

    <!-- Toast notifications -->
    <x-toast position="toast-top toast-end" />
</div>
