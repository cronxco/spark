<div>
    <x-modal wire:model="showHelpModal" title="Keyboard Shortcuts" class="backdrop-blur" persistent>
        <div class="space-y-6">
            {{-- Navigation --}}
            <div>
                <h3 class="text-lg font-semibold mb-3 flex items-center gap-2">
                    <x-icon name="o-map" class="w-5 h-5" />
                    Navigation
                </h3>
                <div class="overflow-x-auto">
                    <table class="table table-sm">
                        <tbody>
                            <tr>
                                <td class="w-32">
                                    <kbd class="kbd kbd-sm">g</kbd>
                                    <kbd class="kbd kbd-sm">d</kbd>
                                </td>
                                <td>Go to Today</td>
                            </tr>
                            <tr>
                                <td>
                                    <kbd class="kbd kbd-sm">g</kbd>
                                    <kbd class="kbd kbd-sm">y</kbd>
                                </td>
                                <td>Go to Yesterday</td>
                            </tr>
                            <tr>
                                <td>
                                    <kbd class="kbd kbd-sm">g</kbd>
                                    <kbd class="kbd kbd-sm">t</kbd>
                                </td>
                                <td>Go to Tags</td>
                            </tr>
                            <tr>
                                <td>
                                    <kbd class="kbd kbd-sm">g</kbd>
                                    <kbd class="kbd kbd-sm">m</kbd>
                                </td>
                                <td>Go to Money</td>
                            </tr>
                            <tr>
                                <td>
                                    <kbd class="kbd kbd-sm">g</kbd>
                                    <kbd class="kbd kbd-sm">x</kbd>
                                </td>
                                <td>Go to Metrics</td>
                            </tr>
                            <tr>
                                <td>
                                    <kbd class="kbd kbd-sm">g</kbd>
                                    <kbd class="kbd kbd-sm">u</kbd>
                                </td>
                                <td>Go to Updates</td>
                            </tr>
                            <tr>
                                <td>
                                    <kbd class="kbd kbd-sm">g</kbd>
                                    <kbd class="kbd kbd-sm">s</kbd>
                                </td>
                                <td>Go to Settings</td>
                            </tr>
                            <tr>
                                <td>
                                    <kbd class="kbd kbd-sm">g</kbd>
                                    <kbd class="kbd kbd-sm">a</kbd>
                                </td>
                                <td>Go to Admin</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- UI Controls --}}
            <div>
                <h3 class="text-lg font-semibold mb-3 flex items-center gap-2">
                    <x-icon name="o-cursor-arrow-rays" class="w-5 h-5" />
                    UI Controls
                </h3>
                <div class="overflow-x-auto">
                    <table class="table table-sm">
                        <tbody>
                            <tr>
                                <td class="w-32">
                                    <kbd class="kbd kbd-sm">b</kbd>
                                </td>
                                <td>Toggle sidebar</td>
                            </tr>
                            <tr>
                                <td>
                                    <kbd class="kbd kbd-sm">/</kbd>
                                </td>
                                <td>Open search (Spotlight)</td>
                            </tr>
                            <tr>
                                <td>
                                    <kbd class="kbd kbd-sm">{{ PHP_OS_FAMILY === 'Darwin' ? '⌘' : 'Ctrl' }}</kbd>
                                    <kbd class="kbd kbd-sm">K</kbd>
                                </td>
                                <td>Open command palette (Spotlight)</td>
                            </tr>
                            <tr>
                                <td>
                                    <kbd class="kbd kbd-sm">?</kbd>
                                </td>
                                <td>Show this help dialog</td>
                            </tr>
                            <tr>
                                <td>
                                    <kbd class="kbd kbd-sm">Esc</kbd>
                                </td>
                                <td>Close modals and drawers</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Detail Page Actions --}}
            <div>
                <h3 class="text-lg font-semibold mb-3 flex items-center gap-2">
                    <x-icon name="o-document-text" class="w-5 h-5" />
                    Detail Page Actions
                </h3>
                <div class="overflow-x-auto">
                    <table class="table table-sm">
                        <tbody>
                            <tr>
                                <td class="w-32">
                                    <kbd class="kbd kbd-sm">d</kbd>
                                </td>
                                <td>Toggle detail drawer</td>
                            </tr>
                            <tr>
                                <td>
                                    <kbd class="kbd kbd-sm">e</kbd>
                                </td>
                                <td>Edit current item</td>
                            </tr>
                            <tr>
                                <td>
                                    <kbd class="kbd kbd-sm">t</kbd>
                                </td>
                                <td>Focus tag input</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Spotlight Modes --}}
            <div>
                <h3 class="text-lg font-semibold mb-3 flex items-center gap-2">
                    <x-icon name="fab.searchengin" class="w-5 h-5" />
                    Spotlight Search Modes
                </h3>
                <div class="overflow-x-auto">
                    <table class="table table-sm">
                        <tbody>
                            <tr>
                                <td class="w-32">
                                    <kbd class="kbd kbd-sm">&gt;</kbd>
                                </td>
                                <td>Actions & Commands</td>
                            </tr>
                            <tr>
                                <td>
                                    <kbd class="kbd kbd-sm">#</kbd>
                                </td>
                                <td>Search Tags</td>
                            </tr>
                            <tr>
                                <td>
                                    <kbd class="kbd kbd-sm">$</kbd>
                                </td>
                                <td>Search Metrics</td>
                            </tr>
                            <tr>
                                <td>
                                    <kbd class="kbd kbd-sm">@</kbd>
                                </td>
                                <td>Search Integrations</td>
                            </tr>
                            <tr>
                                <td>
                                    <kbd class="kbd kbd-sm">!</kbd>
                                </td>
                                <td>Admin Commands</td>
                            </tr>
                            <tr>
                                <td>
                                    <kbd class="kbd kbd-sm">?</kbd>
                                </td>
                                <td>Help & Tips</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <x-slot:actions>
            <x-button label="Close" @click="$wire.toggleModal()" />
        </x-slot:actions>
    </x-modal>
</div>
