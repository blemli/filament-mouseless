<x-filament-panels::page>
    {{-- Singleton mode: presets are invisible plumbing — no locked banner,
         no selector aside; the table stands alone and edits just work. --}}
    @if (! $this->isSingletonMode() && $this->isShortcutsLocked())
        <x-filament::callout
            color="info"
            icon="heroicon-o-lock-closed"
            :heading="__('filament-mouseless::mouseless.table.locked_banner_heading', [
                'preset' => $this->getShortcutsPreset()['name'] ?? '',
            ])"
            :description="__('filament-mouseless::mouseless.table.locked_banner', [
                'preset' => $this->getShortcutsPreset()['name'] ?? '',
                'name' => $this->shortcutsForkName(),
            ])"
        />
    @endif

    <div @class(['fi-mouseless-layout', 'fi-mouseless-layout-singleton' => $this->isSingletonMode()])>
        @unless ($this->isSingletonMode())
            <aside class="fi-mouseless-layout-aside">
                @livewire(\Blemli\FilamentMouseless\Filament\Widgets\PresetSelector::class)
            </aside>
        @endunless

        <div class="fi-mouseless-layout-main">
            {{ $this->table }}
        </div>
    </div>

    <div
        x-data="{
            copy(text) {
                if (navigator.clipboard?.writeText) {
                    navigator.clipboard.writeText(text);
                    return;
                }
                const el = document.createElement('textarea');
                el.value = text;
                el.style.position = 'fixed';
                el.style.opacity = '0';
                document.body.appendChild(el);
                el.select();
                document.execCommand('copy');
                el.remove();
            },
        }"
        x-on:mouseless-copy-export.window="copy($event.detail.json)"
    ></div>
</x-filament-panels::page>
