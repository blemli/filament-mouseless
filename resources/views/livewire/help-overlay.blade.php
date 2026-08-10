<div
    x-data="{
        open: false,
        q: '',
        empty: false,
        filter() {
            const q = this.q.trim().toLowerCase();
            let hits = 0;
            this.$root.querySelectorAll('.fi-mouseless-help-body section').forEach((section) => {
                {{-- A hit on the group heading ("Navigation") keeps its whole group. --}}
                const heading = section.querySelector('.fi-mouseless-help-group-heading');
                const groupHit = q !== '' && heading && heading.textContent.toLowerCase().includes(q);
                let visible = 0;
                section.querySelectorAll('.fi-mouseless-help-item').forEach((item) => {
                    {{-- textContent covers label + displayed keycap (⌥N); data-combo
                         makes the raw form ("alt+n") searchable too. --}}
                    const hay = (item.textContent + ' ' + (item.dataset.combo || '')).toLowerCase();
                    const hit = q === '' || groupHit || hay.includes(q);
                    item.classList.toggle('fi-mouseless-help-item-filtered', ! hit);
                    if (hit) visible++;
                });
                section.classList.toggle('fi-mouseless-help-section-filtered', visible === 0);
                hits += visible;
            });
            this.empty = q !== '' && hits === 0;
        },
    }"
    x-init="$watch('q', () => filter())"
    @mouseless-help.window="open = ! open; if (open) { q = ''; $nextTick(() => $refs.search?.focus()) }"
    @if ($printable)
        {{-- Body class scopes the print stylesheet: without it, Cmd+P prints the page normally. --}}
        x-effect="document.body.classList.toggle('fi-mouseless-help-open', open)"
    @endif
>
    <div
        x-show="open"
        x-cloak
        x-transition.opacity
        data-mouseless-overlay
        @click.self="open = false"
        class="fi-mouseless-help-overlay"
    >
        <div @click.stop class="fi-mouseless-help-panel">
            {{-- Print-only cheatsheet letterhead. --}}
            <div class="fi-mouseless-cheatsheet-brand">
                <div class="fi-mouseless-cheatsheet-app">{{ $brandName }} {{ __('filament-mouseless::mouseless.help.title') }}</div>
                @if ($slogan)
                    <div class="fi-mouseless-cheatsheet-slogan">{{ $slogan }}</div>
                @endif
            </div>

            <div class="fi-mouseless-help-header">
                <h2 class="fi-mouseless-help-title">
                    {{ __('filament-mouseless::mouseless.help.title') }}
                </h2>
                @if ($searchable)
                    <input
                        type="search"
                        x-ref="search"
                        x-model="q"
                        placeholder="{{ __('filament-mouseless::mouseless.help.filter_placeholder') }}"
                        aria-label="{{ __('filament-mouseless::mouseless.help.filter_placeholder') }}"
                        class="fi-mouseless-help-search"
                    />
                @endif
                <button
                    type="button"
                    @click="open = false"
                    aria-label="{{ __('filament-mouseless::mouseless.help.close') }}"
                    class="fi-mouseless-help-close"
                >&times;</button>
            </div>

            <div class="fi-mouseless-help-body">
                @foreach ($groups as $namespace => $actions)
                    <section>
                        <h3 class="fi-mouseless-help-group-heading">
                            {{ __('filament-mouseless::mouseless.ns.' . $namespace) }}
                        </h3>
                        <ul class="fi-mouseless-help-list">
                            @foreach ($actions as $actionId => $key)
                                <li class="fi-mouseless-help-item" data-mouseless-help-item="{{ $actionId }}" data-combo="{{ $key }}">
                                    <span>{{ __('filament-mouseless::mouseless.action.' . $actionId) }}</span>
                                    <kbd class="fi-mouseless-help-kbd">{{ \Blemli\FilamentMouseless\Support\Keys::display($key) }}</kbd>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endforeach

                @if (! empty($customRows))
                    <section>
                        <h3 class="fi-mouseless-help-group-heading">
                            {{ __('filament-mouseless::mouseless.ns.custom') }}
                        </h3>
                        <ul class="fi-mouseless-help-list">
                            @foreach ($customRows as $row)
                                <li
                                    @class([
                                        'fi-mouseless-help-item',
                                        'fi-mouseless-help-item-unavailable' => ! $row['onPage'],
                                    ])
                                    data-mouseless-help-item="{{ $row['id'] }}"
                                    data-combo="{{ $row['combo'] }}"
                                    @if ($row['readonly']) data-mouseless-help-readonly @endif
                                >
                                    <span>
                                        {{ $row['label'] }}
                                        @if ($row['readonly'])
                                            <span
                                                class="fi-mouseless-help-readonly"
                                                title="{{ __('filament-mouseless::mouseless.help.code_defined') }}"
                                            >&lt;/&gt;</span>
                                        @endif
                                    </span>
                                    <kbd class="fi-mouseless-help-kbd">{{ \Blemli\FilamentMouseless\Support\Keys::display($row['combo']) }}</kbd>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                <p x-show="empty" x-cloak class="fi-mouseless-help-empty">
                    {{ __('filament-mouseless::mouseless.help.filter_empty') }}
                </p>
            </div>

            <footer class="fi-mouseless-help-footer">
                <span>
                    @if ($preset)
                        {{ __('filament-mouseless::mouseless.help.source', [
                            'preset' => $preset['name'] ?? $preset['slug'] ?? '',
                        ]) }}
                    @endif
                </span>

                <span class="fi-mouseless-help-footer-meta">
                    @if ($appVersion)
                        <span>v{{ $appVersion }}</span>
                    @endif

                    {{-- Print-only: the date lets paper copies reveal their age. --}}
                    <span class="fi-mouseless-cheatsheet-date">{{ $printedAt }}</span>

                    @if ($printable)
                        <button
                            type="button"
                            @click="window.print()"
                            class="fi-mouseless-help-print"
                        >
                            {{ __('filament-mouseless::mouseless.help.print') }}
                        </button>
                    @endif
                </span>
            </footer>
        </div>
    </div>
</div>
