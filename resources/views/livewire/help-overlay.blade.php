<div
    x-data="{ open: false }"
    @mouseless-help.window="open = ! open"
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
            <div class="fi-mouseless-help-header">
                <h2 class="fi-mouseless-help-title">
                    {{ __('filament-mouseless::mouseless.help.title') }}
                </h2>
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
                                <li class="fi-mouseless-help-item">
                                    <span>{{ __('filament-mouseless::mouseless.action.' . $actionId) }}</span>
                                    <kbd class="fi-mouseless-help-kbd">{{ \Blemli\FilamentMouseless\Support\Keys::display($key) }}</kbd>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endforeach
            </div>

            <footer class="fi-mouseless-help-footer">
                @if ($preset)
                    {{ __('filament-mouseless::mouseless.help.source', [
                        'preset' => $preset['name'] ?? $preset['slug'] ?? '',
                    ]) }}
                @endif
            </footer>
        </div>
    </div>
</div>
