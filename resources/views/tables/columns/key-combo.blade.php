@php
    use Blemli\FilamentMouseless\Support\Keys;

    $record = $getRecord();
@endphp

<div class="fi-mouseless-combo-cell">
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
</div>
