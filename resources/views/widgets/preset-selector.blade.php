<x-filament-widgets::widget>
    <x-filament::section :heading="__('filament-mouseless::mouseless.widget.heading')" compact>
        <div class="fi-mouseless-stack fi-mouseless-widget-stack">
            <div class="fi-mouseless-select-row">
                {{-- Keyed by the options content: renames/new layouts change the key,
                     so Livewire swaps the subtree and the JS select re-initializes
                     with fresh option labels (a morph alone keeps them cached). --}}
                <div
                    class="fi-mouseless-select-field"
                    wire:key="mouseless-preset-select-{{ md5(json_encode($this->getPresetOptions())) }}"
                >
                    {{ $this->form }}
                </div>

                {{ $this->createLayoutAction }}
            </div>

            {{-- Directly echoed actions render as *disabled* buttons when hidden — so guard with isVisible(). --}}
            <div class="fi-mouseless-actions-row">
                @if ($this->renameLayoutAction->isVisible())
                    {{ $this->renameLayoutAction }}
                @endif

                @if ($this->publishLayoutAction->isVisible())
                    {{ $this->publishLayoutAction }}
                @endif

                @if ($this->deleteLayoutAction->isVisible())
                    {{ $this->deleteLayoutAction }}
                @endif

                <x-filament::dropdown placement="bottom-start">
                    <x-slot name="trigger">
                        <x-filament::button color="gray" icon-trailing="heroicon-m-chevron-down">
                            {{ __('filament-mouseless::mouseless.widget.transfer') }}
                        </x-filament::button>
                    </x-slot>

                    <x-filament::dropdown.list>
                        <x-filament::dropdown.list.item
                            icon="heroicon-m-arrow-down-tray"
                            wire:click="mountAction('exportLayout')"
                        >
                            {{ __('filament-mouseless::mouseless.table.export.download') }}
                        </x-filament::dropdown.list.item>

                        <x-filament::dropdown.list.item
                            icon="heroicon-m-clipboard-document"
                            wire:click="mountAction('copyExport')"
                        >
                            {{ __('filament-mouseless::mouseless.table.export.clipboard') }}
                        </x-filament::dropdown.list.item>

                        <x-filament::dropdown.list.item
                            icon="heroicon-m-arrow-up-tray"
                            wire:click="mountAction('importLayout')"
                        >
                            {{ __('filament-mouseless::mouseless.table.import.label') }}
                        </x-filament::dropdown.list.item>
                    </x-filament::dropdown.list>
                </x-filament::dropdown>
            </div>
        </div>
    </x-filament::section>

    <x-filament-actions::modals />
</x-filament-widgets::widget>
