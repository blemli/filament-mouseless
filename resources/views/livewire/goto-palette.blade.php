{{--
    The "go to" palette. Structure & colours lean on Filament-native primitives:
    the backdrop/panel reuse the help-overlay classes (already themed, no extra
    CSS), the accent is the app's own primary colour via Filament's --primary-*
    variables, and rows are real <a> links so Enter / click navigate natively.
    JS (resources/js/index.js) toggles [data-mouseless-goto] and drives filtering.
--}}
<div data-mouseless-goto style="display: none;">
    <div class="fi-mouseless-help-overlay">
        <div class="fi-mouseless-help-panel fi-mouseless-goto-panel" style="max-width: 34rem;">
            {{-- Query box — solid primary, shows what has been typed so far. --}}
            <div
                data-mouseless-goto-query
                style="
                    display: flex;
                    align-items: center;
                    gap: 0.5rem;
                    min-height: 2.75rem;
                    margin-bottom: 1rem;
                    padding: 0.625rem 0.875rem;
                    border-radius: 0.5rem;
                    background-color: var(--primary-600);
                    color: #fff;
                    font-size: 1rem;
                    font-weight: 600;
                "
            >
                <x-filament::icon
                    icon="heroicon-o-magnifying-glass"
                    style="width: 1.1rem; height: 1.1rem; flex: none; opacity: 0.8;"
                />
                <span data-mouseless-goto-text style="white-space: pre;"></span>
                <span
                    data-mouseless-goto-placeholder
                    style="opacity: 0.75; font-weight: 400;"
                >{{ __('filament-mouseless::mouseless.goto.placeholder') }}</span>
            </div>

            <div data-mouseless-goto-body style="display: flex; flex-direction: column; gap: 1rem; max-height: 60vh; overflow-y: auto;">
                @forelse ($groups as $group)
                    <section data-mouseless-goto-group>
                        @if (! empty($group['label']))
                            <h3 class="fi-mouseless-help-group-heading">{{ $group['label'] }}</h3>
                        @endif

                        <div style="display: flex; flex-direction: column; gap: 0.125rem;">
                            @foreach ($group['items'] as $item)
                                <a
                                    href="{{ $item['url'] }}"
                                    data-mouseless-goto-item
                                    data-goto-url="{{ $item['url'] }}"
                                    data-goto-label="{{ mb_strtolower($item['label']) }}"
                                    data-goto-chord="{{ $item['chord'] }}"
                                    style="
                                        display: flex;
                                        align-items: center;
                                        gap: 0.625rem;
                                        padding: 0.5rem 0.625rem;
                                        border-radius: 0.5rem;
                                        text-decoration: none;
                                        color: inherit;
                                    "
                                >
                                    @if (! empty($item['icon']))
                                        <x-filament::icon
                                            :icon="$item['icon']"
                                            style="width: 1.25rem; height: 1.25rem; flex: none; opacity: 0.7;"
                                        />
                                    @endif
                                    {{-- Chord letters (the JetBrains-style camel-hump prefix) underlined per word.
                                         Kept on one line so no whitespace leaks between segments of the label. --}}
                                    <span>@foreach ($item['segments'] as $seg)@if ($seg['hit'])<span style="font-weight: 700; text-decoration: underline; text-decoration-color: var(--primary-500); text-decoration-thickness: 2px; text-underline-offset: 2px;">{{ $seg['text'] }}</span>@else{{ $seg['text'] }}@endif@endforeach</span>
                                </a>
                            @endforeach
                        </div>
                    </section>
                @empty
                    <p class="fi-mouseless-muted">{{ __('filament-mouseless::mouseless.goto.empty') }}</p>
                @endforelse
            </div>

            <footer class="fi-mouseless-help-footer">
                <span>{{ __('filament-mouseless::mouseless.goto.hint') }}</span>
            </footer>
        </div>
    </div>
</div>
