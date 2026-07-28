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
        <x-filament::link tag="button" size="sm" color="primary" wire:click="retryRecording">
            {{ __('filament-mouseless::mouseless.table.steal.retry') }}
        </x-filament::link>
        <x-filament::link tag="button" size="sm" color="gray" wire:click="cancelRecording">
            {{ __('filament-mouseless::mouseless.table.recording.cancel') }}
        </x-filament::link>
    @elseif ($isRecording)
        <span
            data-mouseless-recording
            x-data="{
                capture(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    if (e.key === 'Escape') return this.$wire.cancelRecording();
                    if (['Control','Alt','Shift','Meta'].includes(e.key)) return;
                    const combo = window.mouselessEventToKey?.(e);
                    if (combo) this.$wire.recordKey(combo);
                },
            }"
            {{--
                Alpine owns this window listener: it's attached synchronously as
                the badge initialises and torn down the moment the badge leaves
                the DOM. That's deliberately different from a hand-rolled
                addEventListener in x-init/$nextTick — the old approach deferred
                attachment to an animation frame (which a backgrounded/blurred
                tab never fires, so losing focus mid-rebind left the capture
                dead) and leaked stale handlers across re-renders.
            --}}
            x-on:keydown.window.capture="capture($event)"
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

        @if ($record['readonly'] ?? false)
            <x-filament::badge color="gray" icon="heroicon-m-code-bracket">
                {{ __('filament-mouseless::mouseless.table.code_defined') }}
            </x-filament::badge>
        @endif

        @if ($record['kept'] ?? false)
            <span title="{{ __('filament-mouseless::mouseless.table.kept_tooltip', ['key' => ($record['kept_default'] ?? null) ? Keys::display($record['kept_default']) : '—']) }}">
                <x-filament::badge color="info" icon="heroicon-m-hand-raised">
                    {{ __('filament-mouseless::mouseless.table.kept') }}
                </x-filament::badge>
            </span>
        @endif

        @if (($record['cross_locale'] ?? []) !== [])
            <span title="{{ implode(' · ', $record['cross_locale']) }}">
                <x-filament::badge color="warning" icon="heroicon-m-language">
                    {{ __('filament-mouseless::mouseless.table.cross_locale') }}
                </x-filament::badge>
            </span>
        @endif

        @if ($record['admin_disabled'] ?? false)
            <span title="{{ __('filament-mouseless::mouseless.table.admin_disabled_tooltip') }}">
                <x-filament::badge color="gray" icon="heroicon-m-lock-closed">
                    {{ __('filament-mouseless::mouseless.table.admin_disabled') }}
                </x-filament::badge>
            </span>
        @endif
    @endif
</div>
