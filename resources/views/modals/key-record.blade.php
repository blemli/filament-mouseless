@php
    use Blemli\FilamentMouseless\Support\ActionMeta;
    use Blemli\FilamentMouseless\Support\Keys;
@endphp

{{--
    data-mouseless-recording sits on the root (not just the capture span) so
    global shortcuts stay suppressed while the steal prompt is up too.
--}}
<div
    data-mouseless-recording
    class="fi-mouseless-key-record-modal"
>
    @if ($steal)
        <x-filament::badge color="danger">
            {{ __('filament-mouseless::mouseless.table.steal.taken', [
                'key' => Keys::display($steal['combo']),
                'action' => ActionMeta::label($steal['otherActionId']),
            ]) }}
        </x-filament::badge>
        <div class="fi-mouseless-key-record-steal-actions">
            <x-filament::link tag="button" size="sm" color="danger" wire:click="confirmSteal">
                {{ __('filament-mouseless::mouseless.table.steal.confirm') }}
            </x-filament::link>
            <x-filament::link tag="button" size="sm" color="primary" wire:click="retryRecording">
                {{ __('filament-mouseless::mouseless.table.steal.retry') }}
            </x-filament::link>
        </div>
    @else
        <div
            x-data="{
                parts: [],
                sent: false,
                held(e) {
                    const held = [];
                    if (e.ctrlKey) held.push('ctrl');
                    if (e.metaKey) held.push('cmd');
                    if (e.altKey) held.push('alt');
                    if (e.shiftKey) held.push('shift');
                    return window.mouselessComboDisplayParts?.(held.join('+')) ?? held;
                },
                capture(e) {
                    if (this.sent) return; {{-- Combo already sent — freeze the caps until the server closes the modal. --}}
                    if (e.key === 'Escape') return; {{-- Filament closes the modal itself. --}}
                    e.preventDefault();
                    e.stopPropagation();
                    if (['Control','Alt','Shift','Meta'].includes(e.key)) {
                        this.parts = this.held(e);
                        return;
                    }
                    const combo = window.mouselessEventToKey?.(e);
                    if (! combo) return;
                    this.parts = window.mouselessComboDisplayParts?.(combo) ?? [combo];
                    this.sent = true;
                    this.$wire.recordKey(combo);
                },
                release(e) {
                    if (! this.sent) this.parts = this.held(e);
                },
            }"
            {{--
                Alpine owns these window listeners (attached on init, removed
                when the div leaves the DOM — steal prompt or modal close)
                rather than a hand-rolled addEventListener in x-init/$nextTick —
                the deferred attach never ran in a backgrounded tab.
            --}}
            x-on:keydown.window.capture="capture($event)"
            x-on:keyup.window.capture="release($event)"
            class="fi-mouseless-key-record-capture"
        >
            <x-filament::badge color="warning" icon="heroicon-m-key" size="lg">
                {{ __('filament-mouseless::mouseless.table.recording.press') }}
            </x-filament::badge>

            {{-- Live keycaps: held modifiers show while pressed; the final
                 combo stays frozen until the modal closes. --}}
            <div class="fi-mouseless-key-record-caps" aria-hidden="true">
                <template x-for="(part, i) in parts" :key="i + '-' + part">
                    <kbd class="fi-mouseless-key-record-cap" x-text="part"></kbd>
                </template>
            </div>
        </div>
    @endif
</div>
