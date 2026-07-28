<x-filament-panels::page>
    <form wire:submit="save">
        <x-filament::section>
            {{ $this->form }}

            <div class="fi-mouseless-row-end">
                <x-filament::button type="submit" icon="heroicon-o-cursor-arrow-ripple">
                    {{ __('filament-mouseless::mouseless.admin.save') }}
                </x-filament::button>
            </div>
        </x-filament::section>
    </form>

    @if ($this->hasDefaultsTable())
        <x-filament::section
            :heading="__('filament-mouseless::mouseless.admin.defaults_heading')"
            :description="__('filament-mouseless::mouseless.admin.defaults_hint')"
        >
            <div class="fi-mouseless-admin-defaults-table">
                {{ $this->table }}
            </div>
        </x-filament::section>
    @endif

    @php($stats = $this->getStatisticsSummary())
    @if ($stats !== null)
        <x-filament::section :heading="__('filament-mouseless::mouseless.admin.stats_heading')">
            <div class="fi-mouseless-admin-stats">
                <div class="fi-mouseless-admin-stats-totals">
                    <div class="fi-mouseless-admin-stat">
                        <span class="fi-mouseless-stats-number">{{ \Illuminate\Support\Number::format($stats['totals']['keyboard']) }}</span>
                        <span class="fi-mouseless-stats-caption">{{ trans_choice('filament-mouseless::mouseless.stats.clicks_avoided', $stats['totals']['keyboard']) }}</span>
                    </div>
                    <div class="fi-mouseless-admin-stat">
                        <span class="fi-mouseless-stats-number">{{ \Illuminate\Support\Number::format($stats['totals']['users']) }}</span>
                        <span class="fi-mouseless-stats-caption">{{ __('filament-mouseless::mouseless.admin.stats_users') }}</span>
                    </div>
                </div>

                @if ($stats['topActions'] !== [])
                    <div>
                        <div class="fi-mouseless-stats-subheading">{{ __('filament-mouseless::mouseless.admin.stats_top_actions') }}</div>
                        <ul class="fi-mouseless-admin-stats-list">
                            @foreach ($stats['topActions'] as $action)
                                <li>
                                    <span>{{ $action['label'] }}</span>
                                    <span class="fi-mouseless-muted">{{ \Illuminate\Support\Number::format($action['keyboard']) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if ($stats['leaderboard'] !== [])
                    <div>
                        <div class="fi-mouseless-stats-subheading">{{ __('filament-mouseless::mouseless.admin.stats_leaderboard') }}</div>
                        <ol class="fi-mouseless-admin-stats-list">
                            @foreach ($stats['leaderboard'] as $entry)
                                <li>
                                    <span>{{ $entry['name'] }}</span>
                                    <span class="fi-mouseless-muted">{{ \Illuminate\Support\Number::format($entry['keyboard']) }}</span>
                                </li>
                            @endforeach
                        </ol>
                    </div>
                @endif
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
