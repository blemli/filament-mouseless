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

    @if (config('mouseless.publishing.enabled') && config('mouseless.admin.moderation_queue', true))
        <x-filament::section :heading="__('filament-mouseless::mouseless.admin.moderation_queue')">
            @php($queue = $this->getModerationQueue())

            @if (empty($queue))
                <p class="fi-mouseless-muted">
                    {{ __('filament-mouseless::mouseless.admin.queue_empty') }}
                </p>
            @else
                <ul class="fi-mouseless-queue">
                    @foreach ($queue as $preset)
                        <li class="fi-mouseless-queue-item">
                            <div>
                                <div class="fi-mouseless-queue-name">{{ $preset->name }}</div>
                                <div class="fi-mouseless-queue-meta">{{ $preset->slug }} · {{ $preset->locale }}</div>
                            </div>
                            <div class="fi-mouseless-queue-actions">
                                <x-filament::button size="sm" color="success" wire:click="approve({{ $preset->id }})">
                                    {{ __('filament-mouseless::mouseless.admin.approve') }}
                                </x-filament::button>
                                <x-filament::button size="sm" color="danger" wire:click="reject({{ $preset->id }})">
                                    {{ __('filament-mouseless::mouseless.admin.reject') }}
                                </x-filament::button>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
