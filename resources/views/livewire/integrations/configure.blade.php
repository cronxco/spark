

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
            $this->configuration['update_frequency_minutes'] = $integration->update_frequency_minutes ?? 15;
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
            $updateData = ['configuration' => []];

            // Process the current configuration state
            foreach ($this->configuration as $field => $value) {
                if ($field === 'update_frequency_minutes') {
                    $updateData['update_frequency_minutes'] = $value;
                } else {
                    // Handle array fields that might come as strings
                    if (isset($this->schema[$field]) && $this->schema[$field]['type'] === 'array' && is_string($value)) {
                        $updateData['configuration'][$field] = array_filter(array_map('trim', explode(',', $value)));
                    } else {
                        $updateData['configuration'][$field] = $value;
                    }
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
                    @foreach ($schema as $field => $config)
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
