<x-filament-widgets::widget>
    <x-filament::section :heading="__('filament-mouseless::mouseless.stats.heading')" compact>
        <div class="fi-mouseless-stack fi-mouseless-stats">
            @php($avoided = $this->clicksAvoided())

            <div class="fi-mouseless-stats-headline">
                <span class="fi-mouseless-stats-number">{{ \Illuminate\Support\Number::format($avoided) }}</span>
                <span class="fi-mouseless-stats-caption">{{ trans_choice('filament-mouseless::mouseless.stats.clicks_avoided', $avoided) }}</span>
            </div>

            @if ($avoided === 0)
                <p class="fi-mouseless-muted">{{ __('filament-mouseless::mouseless.stats.empty') }}</p>
            @else
                @if ($this->hasTrend())
                    <div class="fi-mouseless-stats-trend" title="{{ __('filament-mouseless::mouseless.stats.trend_label') }}">
                        <svg viewBox="0 0 100 32" preserveAspectRatio="none" role="img" aria-label="{{ __('filament-mouseless::mouseless.stats.trend_label') }}">
                            <polyline points="{{ $this->trendPoints() }}" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        <span class="fi-mouseless-stats-trend-caption">{{ __('filament-mouseless::mouseless.stats.trend_label') }}</span>
                    </div>
                @endif

                @php($untapped = $this->untappedActions())
                @if ($untapped !== [])
                    <div class="fi-mouseless-stats-untapped">
                        <div class="fi-mouseless-stats-subheading">{{ __('filament-mouseless::mouseless.stats.untapped_heading') }}</div>
                        <p class="fi-mouseless-muted">{{ __('filament-mouseless::mouseless.stats.untapped_hint') }}</p>
                        <ul>
                            @foreach ($untapped as $action)
                                <li>
                                    <span class="fi-mouseless-stats-untapped-label">{{ $action['label'] }}</span>
                                    <x-filament::badge color="gray">{{ $action['combo'] }}</x-filament::badge>
                                    <span class="fi-mouseless-stats-untapped-counts">
                                        {{ __('filament-mouseless::mouseless.stats.untapped_counts', ['clicks' => $action['clicks'], 'kb' => $action['kb']]) }}
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
