<x-filament-panels::page>
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
</x-filament-panels::page>
