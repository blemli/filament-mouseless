<div class="fi-mouseless-stack">
    <x-filament::section :heading="__('filament-mouseless::mouseless.profile.active_preset')" compact>
        <x-filament::input.wrapper>
            <x-filament::input.select wire:model.live="activePresetSlug">
                @foreach ($presets as $slug => $p)
                    <option value="{{ $slug }}">
                        {{ ($p['name'] ?? $slug) }} [{{ $p['locale'] ?? '?' }}]
                    </option>
                @endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>
    </x-filament::section>

    <x-filament::section :heading="__('filament-mouseless::mouseless.profile.bindings')" compact>
        <table class="fi-mouseless-bindings">
            <tbody>
                @foreach ($bindings as $actionId => $key)
                    <tr>
                        <td>{{ __('filament-mouseless::mouseless.action.' . $actionId) }}</td>
                        <td class="fi-mouseless-bindings-cell-key">
                            <x-filament::badge color="gray">{{ $key }}</x-filament::badge>
                        </td>
                        <td class="fi-mouseless-bindings-cell-action">
                            @if ($recordingActionId === $actionId)
                                <span
                                    x-data
                                    x-init="
                                        $nextTick(() => {
                                            const handler = (e) => {
                                                e.preventDefault();
                                                if (['Control','Alt','Shift','Meta'].includes(e.key)) return;
                                                const parts = [];
                                                if (e.ctrlKey)  parts.push('ctrl');
                                                if (e.metaKey)  parts.push('cmd');
                                                if (e.altKey)   parts.push('alt');
                                                if (e.shiftKey) parts.push('shift');

                                                let k = e.key;
                                                const hasMod = e.ctrlKey || e.metaKey || e.altKey;
                                                if (hasMod && /^Key[A-Z]$/.test(e.code))           k = e.code.slice(3).toLowerCase();
                                                else if (hasMod && /^Digit[0-9]$/.test(e.code))    k = e.code.slice(5);
                                                else if (k === 'Dead' && /^Key[A-Z]$/.test(e.code)) k = e.code.slice(3).toLowerCase();
                                                else                                                k = k.toLowerCase();

                                                parts.push(k);
                                                $wire.recordKey(parts.join('+'));
                                                window.removeEventListener('keydown', handler, true);
                                            };
                                            window.addEventListener('keydown', handler, true);
                                        })
                                    "
                                >
                                    <x-filament::badge color="warning">
                                        {{ __('filament-mouseless::mouseless.profile.press_key') }}
                                    </x-filament::badge>
                                </span>
                                <x-filament::link tag="button" size="sm" color="gray" wire:click="cancelRecording">
                                    {{ __('filament-mouseless::mouseless.profile.cancel') }}
                                </x-filament::link>
                            @else
                                <x-filament::link tag="button" size="sm" wire:click="startRecording('{{ $actionId }}')">
                                    {{ __('filament-mouseless::mouseless.profile.rebind') }}
                                </x-filament::link>
                                @if (array_key_exists($actionId, $this->overrides))
                                    <x-filament::link tag="button" size="sm" color="gray" wire:click="clearOverride('{{ $actionId }}')">
                                        {{ __('filament-mouseless::mouseless.profile.reset') }}
                                    </x-filament::link>
                                @endif
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-filament::section>

    <section class="fi-mouseless-actions-row">
        <x-filament::button
            x-data
            @click="navigator.clipboard.writeText(@js($this->exportJson()))"
            color="gray"
            size="sm"
        >
            {{ __('filament-mouseless::mouseless.profile.export') }}
        </x-filament::button>

        @if ($canPublish)
            <x-filament::button wire:click="forkToPublish" color="gray" size="sm">
                {{ __('filament-mouseless::mouseless.profile.fork_publish') }}
            </x-filament::button>
            <x-filament::button wire:click="publish" color="gray" size="sm">
                {{ __('filament-mouseless::mouseless.profile.publish') }}
            </x-filament::button>
            <x-filament::button wire:click="unpublish" color="gray" size="sm">
                {{ __('filament-mouseless::mouseless.profile.unpublish') }}
            </x-filament::button>
        @endif
    </section>

    <x-filament::section :heading="__('filament-mouseless::mouseless.profile.import')" compact>
        <x-filament::input.wrapper>
            <textarea
                wire:model="importJson"
                rows="4"
                class="fi-input fi-mouseless-import-textarea"
                placeholder='{"name":"My preset","locale":"en","bindings":{"crud.create":"alt+c"}}'
            ></textarea>
        </x-filament::input.wrapper>
        <div class="fi-mouseless-import-submit">
            <x-filament::button wire:click="importPreset" size="sm">
                {{ __('filament-mouseless::mouseless.profile.import_button') }}
            </x-filament::button>
        </div>
    </x-filament::section>
</div>
