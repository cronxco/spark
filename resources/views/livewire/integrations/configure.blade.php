

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

        $this->schema = $pluginClass::getConfigurationSchema();
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
                $validTz = in_array($this->configuration['schedule_timezone'], \DateTimeZone::listIdentifiers(), true);
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

        // Generic scheduling + task validations (not in schema)
        $useSchedule = (bool) ($this->configuration['use_schedule'] ?? false);
        $rules['configuration.use_schedule'] = ['nullable'];
        $rules['configuration.paused'] = ['nullable'];
        $rules['configuration.schedule_timezone'] = [$useSchedule ? 'required' : 'nullable', 'string'];
        $rules['configuration.schedule_times'] = [$useSchedule ? 'required' : 'nullable', 'array', 'min:1'];
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

<div class="py-12">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <x-card title="{{ __('Configure Integration') }}" shadow>
                <!-- Integration Name Section -->
                <div class="mb-6 p-4 bg-base-200 rounded-lg">
                    <div class="flex items-center justify-between">
                        <div>
                            <h4 class="text-lg font-medium">{{ __('Integration Name') }}</h4>
                            <p class="text-sm text-base-content/70">{{ __('Give this integration instance a custom name') }}</p>
                        </div>
                        <div class="flex items-center space-x-2">
                            <x-input
                                wire:model.live.debounce.500ms="name"
                                placeholder="Enter integration name"
                                class="w-64"
                            />
                            <x-button
                                label="{{ __('Update Name') }}"
                                wire:click="updateName"
                                class="btn-primary"
                            />
                        </div>
                    </div>
                </div>



                <!-- Configuration Form -->
                <form wire:submit="updateConfiguration" class="space-y-6">
                    <!-- Scheduling & Pause -->
                    <div class="p-4 bg-base-200 rounded-lg">
                        <div class="mb-4">
                            <h4 class="text-lg font-medium">{{ __('Scheduling') }}</h4>
                            <p class="text-sm text-base-content/70">{{ __('Enable a fixed daily schedule or use frequency-based updates.') }}</p>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <x-toggle
                                    label="{{ __('Use schedule instead of frequency') }}"
                                    wire:model="configuration.use_schedule"
                                />
                            </div>
                            <div>
                                <x-toggle
                                    label="{{ __('Paused') }}"
                                    wire:model="configuration.paused"
                                />
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4" x-data>
                            <div>
                                <x-input
                                    label="{{ __('Schedule Times (HH:mm, comma or space separated)') }}"
                                    placeholder="04:10 10:10 16:10 22:10"
                                    wire:model="configuration.schedule_times"
                                />
                            </div>
                            <div>
                                <x-input
                                    label="{{ __('Schedule Timezone') }}"
                                    placeholder="UTC"
                                    wire:model="configuration.schedule_timezone"
                                />
                            </div>
                            <div>
                                <x-input
                                    type="number"
                                    label="{{ __('Fallback Frequency (minutes)') }}"
                                    placeholder="60"
                                    wire:model="configuration.update_frequency_minutes"
                                    min="1"
                                />
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
                        <div class="p-4 bg-base-200 rounded-lg">
                            <div class="mb-4">
                                <h4 class="text-lg font-medium">{{ $config['label'] }}</h4>
                                @if (isset($config['description']))
                                    <p class="text-sm text-base-content/70">{{ $config['description'] }}</p>
                                @endif
                            </div>

                            <div class="space-y-3">
                                @if ($config['type'] === 'array' && isset($config['options']))
                                    @foreach ($config['options'] as $value => $label)
                                        <div class="flex items-center">
                                            <input
                                                type="checkbox"
                                                id="{{ $field }}_{{ $value }}"
                                                wire:click="toggleCheckbox('{{ $field }}', '{{ $value }}')"
                                                @checked(in_array($value, $configuration[$field] ?? []))
                                                class="checkbox"
                                            />
                                            <label for="{{ $field }}_{{ $value }}" class="ml-2 text-sm">
                                                {{ $label }}
                                            </label>
                                        </div>
                                    @endforeach
                                @elseif ($config['type'] === 'array')
                                    <x-textarea
                                        wire:model="configuration.{{ $field }}"
                                        rows="3"
                                        placeholder="Enter values separated by commas"
                                    />
                                @elseif ($config['type'] === 'string' && ($config['options'] ?? null))
                                    <select class="select select-bordered" wire:model="configuration.{{ $field }}">
                                        @foreach (($config['options'] ?? []) as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                @elseif ($config['type'] === 'string')
                                    <x-input
                                        wire:model="configuration.{{ $field }}"
                                        placeholder="Enter {{ strtolower($config['label']) }}"
                                    />
                                @elseif ($config['type'] === 'integer')
                                    <x-input
                                        type="number"
                                        wire:model="configuration.{{ $field }}"
                                        min="{{ $config['min'] ?? 1 }}"
                                        placeholder="Enter {{ strtolower($config['label']) }}"
                                    />
                                @endif

                                @error("configuration.{$field}")
                                    <p class="text-sm text-error">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>
                        @endif
                    @endforeach

                    <div class="flex justify-end space-x-3 pt-6 border-t border-base-300">
                        <x-button
                            label="{{ __('Cancel') }}"
                            link="{{ route('integrations.index') }}"
                            class="btn-outline"
                        />

                        <x-button
                            label="{{ __('Save Configuration') }}"
                            type="submit"
                            class="btn-primary"
                        />
                    </div>
                </form>
            </x-card>
        </div>
    </div>
