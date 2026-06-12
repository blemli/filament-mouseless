@php
    use Blemli\FilamentMouseless\Support\ActionMeta;
    use Blemli\FilamentMouseless\Support\Keys;

    $record = $getRecord();
    $livewire = $getTable()->getLivewire();
    $isRecording = $livewire->recordingActionId === $record['id'];
    $steal = $isRecording ? $livewire->pendingSteal : null;
@endphp

<div class="fi-mouseless-combo-cell">
    @if ($isRecording && $steal)
        <x-filament::badge color="danger">
            {{ __('filament-mouseless::mouseless.table.steal.taken', [
                'key' => Keys::display($steal['combo']),
                'action' => ActionMeta::label($steal['otherActionId']),
            ]) }}
        </x-filament::badge>
        <x-filament::link tag="button" size="sm" color="danger" wire:click="confirmSteal">
            {{ __('filament-mouseless::mouseless.table.steal.confirm') }}
        </x-filament::link>
        <x-filament::link tag="button" size="sm" color="gray" wire:click="cancelRecording">
            {{ __('filament-mouseless::mouseless.table.recording.cancel') }}
        </x-filament::link>
    @elseif ($isRecording)
        <span
            data-mouseless-recording
            x-data
            x-init="
                $nextTick(() => {
                    const handler = (e) => {
                        if (! $el.isConnected) {
                            window.removeEventListener('keydown', handler, true);
                            return;
                        }
                        e.preventDefault();
                        e.stopPropagation();
                        if (e.key === 'Escape') {
                            window.removeEventListener('keydown', handler, true);
                            $wire.cancelRecording();
                            return;
                        }
                        if (['Control','Alt','Shift','Meta'].includes(e.key)) return;
                        const combo = window.mouselessEventToKey?.(e);
                        if (! combo) return;
                        window.removeEventListener('keydown', handler, true);
                        $wire.recordKey(combo);
                    };
                    window.addEventListener('keydown', handler, true);
                })
            "
        >
            <x-filament::badge color="warning">
                {{ __('filament-mouseless::mouseless.table.recording.press') }}
            </x-filament::badge>
        </span>
        <x-filament::link tag="button" size="sm" color="gray" wire:click="cancelRecording">
            {{ __('filament-mouseless::mouseless.table.recording.cancel') }}
        </x-filament::link>
    @else
        @if ($record['combo'] !== null)
            <span class="fi-mouseless-kbd-group">
                @foreach (Keys::displayParts($record['combo']) as $part)
                    <kbd class="fi-mouseless-kbd">{{ $part }}</kbd>
                @endforeach
            </span>
        @elseif (! $record['disabled'])
            <x-filament::badge color="warning" icon="heroicon-m-exclamation-triangle">
                {{ __('filament-mouseless::mouseless.table.unbound_warning') }}
            </x-filament::badge>
        @else
            <span class="fi-mouseless-combo-empty" title="{{ __('filament-mouseless::mouseless.table.unbound') }}">—</span>
        @endif

        @if ($record['duplicate'])
            <x-filament::badge color="danger" icon="heroicon-m-exclamation-triangle">
                {{ __('filament-mouseless::mouseless.table.duplicate') }}
            </x-filament::badge>
        @endif
    @endif
</div>
