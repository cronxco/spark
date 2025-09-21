<div>
    <x-modal wire:model="showModal" title="Delete Integration Group" class="modal-lg">
        <div class="space-y-6">
            <!-- Step 1: Warning -->
            <div x-show="step === 1" x-transition>
                <div class="space-y-4">
                    <x-alert icon="o-exclamation-triangle" class="alert-warning">
                        <div class="font-semibold">Warning: This action cannot be undone!</div>
                        <div class="mt-2">
                            You are about to permanently delete the entire <strong>{{ $deletionSummary['service_name'] ?? '' }}</strong>
                            integration group{{ isset($deletionSummary['account_id']) && $deletionSummary['account_id'] ? ' for account ' . $deletionSummary['account_id'] : '' }}.
                        </div>
                    </x-alert>

                    <div class="card bg-base-200">
                        <div class="card-body">
                            <h4 class="font-semibold mb-3">The following data will be permanently deleted:</h4>
                            <div class="grid grid-cols-2 gap-4 text-sm">
                                <div class="flex justify-between">
                                    <span>Integration instances:</span>
                                    <x-badge :value="$deletionSummary['integrations'] ?? 0" class="badge-neutral" />
                                </div>
                                <div class="flex justify-between">
                                    <span>Events:</span>
                                    <x-badge :value="$deletionSummary['events'] ?? 0" class="badge-neutral" />
                                </div>
                                <div class="flex justify-between">
                                    <span>Blocks:</span>
                                    <x-badge :value="$deletionSummary['blocks'] ?? 0" class="badge-neutral" />
                                </div>
                                <div class="flex justify-between">
                                    <span>Objects:</span>
                                    <x-badge :value="$deletionSummary['objects'] ?? 0" class="badge-neutral" />
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="text-sm text-base-content/70">
                        <p>This includes all historical data, activity logs, and any associated content from this integration group.</p>
                    </div>
                </div>
            </div>

            <!-- Step 2: Confirmation -->
            <div x-show="step === 2" x-transition>
                <div class="space-y-4">
                    <div class="text-center">
                        <x-icon name="o-shield-exclamation" class="w-16 h-16 text-error mx-auto mb-4" />
                        <h3 class="text-lg font-semibold mb-2">Confirm Deletion</h3>
                        <p class="text-base-content/70">
                            To confirm this deletion, please type the service name:
                        </p>
                        <div class="mt-2">
                            <code class="bg-base-300 px-3 py-1 rounded text-lg font-mono">
                                {{ $deletionSummary['service_name'] ?? '' }}
                            </code>
                        </div>
                    </div>

                    <div>
                        <x-input
                            wire:model.live="confirmationText"
                            placeholder="Type the service name here"
                            class="text-center text-lg"
                            autocomplete="off"
                        />
                        @error('confirmationText')
                            <div class="text-error text-sm mt-1">{{ $message }}</div>
                        @enderror
                    </div>

                    @if ($confirmationText && !$this->validateConfirmationText())
                        <div class="text-warning text-sm text-center">
                            <x-icon name="o-exclamation-triangle" class="w-4 h-4 inline mr-1" />
                            Service name doesn't match. Please check and try again.
                        </div>
                    @endif
                </div>
            </div>

            <!-- Step 3: Final confirmation -->
            <div x-show="step === 3" x-transition>
                <div class="space-y-4">
                    <div class="text-center">
                        <x-icon name="o-trash" class="w-16 h-16 text-error mx-auto mb-4" />
                        <h3 class="text-lg font-semibold mb-2">Final Confirmation</h3>
                        <p class="text-base-content/70 mb-4">
                            This is your last chance to cancel. Once confirmed, the deletion will begin immediately and cannot be stopped.
                        </p>
                    </div>

                    <div class="card bg-error/10 border border-error/20">
                        <div class="card-body">
                            <div class="flex items-start space-x-3">
                                <x-icon name="o-exclamation-triangle" class="w-6 h-6 text-error mt-0.5" />
                                <div>
                                    <h4 class="font-semibold text-error mb-2">What happens next:</h4>
                                    <ul class="text-sm space-y-1">
                                        <li>• All data will be permanently deleted from the database</li>
                                        <li>• This action cannot be undone or recovered</li>
                                        <li>• The deletion process may take a few moments</li>
                                        <li>• You will be redirected back to the integrations page</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-control">
                        <label class="label cursor-pointer justify-start space-x-3">
                            <input
                                type="checkbox"
                                wire:model.live="finalConfirmation"
                                class="checkbox checkbox-error"
                            />
                            <span class="label-text">
                                I understand this action cannot be undone and I want to proceed with the deletion
                            </span>
                        </label>
                    </div>
                </div>
            </div>
        </div>

        <x-slot:actions>
            <div class="flex justify-between w-full">
                <div>
                    @if ($step > 1)
                        <x-button
                            label="Back"
                            icon="o-arrow-left"
                            class="btn-outline"
                            wire:click="previousStep"
                            :disabled="$isDeleting"
                        />
                    @endif
                </div>

                <div class="flex space-x-2">
                    <x-button
                        label="Cancel"
                        class="btn-ghost"
                        wire:click="closeModal"
                        :disabled="$isDeleting"
                    />

                    @if ($step < 3)
                        <x-button
                            label="Continue"
                            icon="o-arrow-right"
                            class="btn-primary"
                            wire:click="nextStep"
                            :disabled="($step === 2 && !$this->validateConfirmationText()) || $isDeleting"
                        />
                    @else
                        <x-button
                            label="{{ $isDeleting ? 'Deleting...' : 'Delete Group' }}"
                            icon="{{ $isDeleting ? 'o-cog-6-tooth' : 'o-trash' }}"
                            class="btn-error"
                            wire:click="deleteGroup"
                            :disabled="!$finalConfirmation || $isDeleting"
                            spinner="deleteGroup"
                        />
                    @endif
                </div>
            </div>
        </x-slot:actions>
    </x-modal>

    <!-- Progress Modal -->
    <x-modal wire:model="showProgress" title="Deleting Integration Group" class="modal-lg" :closable="false">
        <div class="space-y-6" wire:poll.2s="checkProgress">
            <!-- Progress Bar -->
            <div class="space-y-2">
                <div class="flex justify-between text-sm">
                    <span class="font-medium">{{ $progressMessage }}</span>
                    <span class="text-base-content/70">{{ $progressPercentage }}%</span>
                </div>
                <progress class="progress progress-primary w-full" value="{{ $progressPercentage }}" max="100"></progress>
            </div>

            <!-- Step Details -->
            @if ($progressDetails)
                <div class="card bg-base-200">
                    <div class="card-body">
                        <h4 class="font-semibold mb-3">Deletion Details:</h4>
                        <div class="grid grid-cols-2 gap-4 text-sm">
                            @if (isset($progressDetails['integrations']))
                                <div class="flex justify-between">
                                    <span>Integration instances:</span>
                                    <x-badge :value="$progressDetails['integrations']" class="badge-neutral" />
                                </div>
                            @endif
                            @if (isset($progressDetails['events']))
                                <div class="flex justify-between">
                                    <span>Events:</span>
                                    <x-badge :value="$progressDetails['events']" class="badge-neutral" />
                                </div>
                            @endif
                            @if (isset($progressDetails['blocks']))
                                <div class="flex justify-between">
                                    <span>Blocks:</span>
                                    <x-badge :value="$progressDetails['blocks']" class="badge-neutral" />
                                </div>
                            @endif
                            @if (isset($progressDetails['objects']))
                                <div class="flex justify-between">
                                    <span>Objects:</span>
                                    <x-badge :value="$progressDetails['objects']" class="badge-neutral" />
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            @endif

            <!-- Current Step -->
            <div class="text-center">
                <div class="text-sm text-base-content/70">
                    @switch($progressStep)
                        @case('starting')
                            <x-icon name="o-play" class="w-6 h-6 text-primary mx-auto mb-2" />
                            <p>Initializing deletion process...</p>
                            @break
                        @case('analyzing')
                            <x-icon name="o-magnifying-glass" class="w-6 h-6 text-info mx-auto mb-2" />
                            <p>Analyzing data to be deleted...</p>
                            @break
                        @case('deleting_blocks')
                            <x-icon name="o-trash" class="w-6 h-6 text-warning mx-auto mb-2" />
                            <p>Deleting content blocks...</p>
                            @break
                        @case('deleting_events')
                            <x-icon name="o-calendar" class="w-6 h-6 text-warning mx-auto mb-2" />
                            <p>Deleting events...</p>
                            @break
                        @case('finding_orphans')
                            <x-icon name="o-magnifying-glass" class="w-6 h-6 text-info mx-auto mb-2" />
                            <p>Finding orphaned objects...</p>
                            @break
                        @case('deleting_objects')
                            <x-icon name="o-cube" class="w-6 h-6 text-warning mx-auto mb-2" />
                            <p>Deleting orphaned objects...</p>
                            @break
                        @case('cleaning_logs')
                            <x-icon name="o-document-text" class="w-6 h-6 text-warning mx-auto mb-2" />
                            <p>Cleaning up activity logs...</p>
                            @break
                        @case('deleting_integrations')
                            <x-icon name="o-link" class="w-6 h-6 text-warning mx-auto mb-2" />
                            <p>Deleting integration instances...</p>
                            @break
                        @case('deleting_group')
                            <x-icon name="o-folder" class="w-6 h-6 text-warning mx-auto mb-2" />
                            <p>Deleting integration group...</p>
                            @break
                        @case('completed')
                            <x-icon name="o-check-circle" class="w-6 h-6 text-success mx-auto mb-2" />
                            <p>Deletion completed successfully!</p>
                            @break
                        @case('failed')
                            <x-icon name="o-x-circle" class="w-6 h-6 text-error mx-auto mb-2" />
                            <p>Deletion failed</p>
                            @break
                        @default
                            <x-icon name="o-cog-6-tooth" class="w-6 h-6 text-primary mx-auto mb-2" />
                            <p>Processing...</p>
                    @endswitch
                </div>
            </div>
        </div>

        <x-slot:actions>
            @if ($progressStep === 'completed')
                <x-button
                    label="Close"
                    class="btn-primary"
                    wire:click="handleDeletionComplete"
                />
            @elseif ($progressStep === 'failed')
                <x-button
                    label="Close"
                    class="btn-error"
                    wire:click="closeModal"
                />
            @else
                <div class="text-sm text-base-content/70 text-center w-full">
                    Please wait while the deletion process completes...
                </div>
            @endif
        </x-slot:actions>
    </x-modal>
</div>
