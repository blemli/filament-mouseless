<?php

namespace Blemli\FilamentMouseless\Support;

use Blemli\FilamentMouseless\Services\CustomActionRegistry;
use Illuminate\Support\Str;

class ActionMeta
{
    protected const ICONS = [
        'crud.create' => 'heroicon-o-plus',
        'crud.edit' => 'heroicon-o-pencil-square',
        'crud.delete' => 'heroicon-o-trash',
        'crud.save' => 'heroicon-o-check',
        'crud.cancel' => 'heroicon-o-x-mark',
        'crud.view' => 'heroicon-o-eye',
        'crud.duplicate' => 'heroicon-o-document-duplicate',
        'list.search' => 'heroicon-o-magnifying-glass',
        'list.filter' => 'heroicon-o-funnel',
        'list.refresh' => 'heroicon-o-arrow-path',
        'list.export' => 'heroicon-o-arrow-down-tray',
        'list.import' => 'heroicon-o-arrow-up-tray',
        'list.select-all' => 'heroicon-o-check-circle',
        'list.bulk-action' => 'heroicon-o-bolt',
        'list.next-page' => 'heroicon-o-chevron-double-right',
        'list.prev-page' => 'heroicon-o-chevron-double-left',
        'list.next-row' => 'heroicon-o-chevron-down',
        'list.prev-row' => 'heroicon-o-chevron-up',
        'list.toggle-row' => 'heroicon-o-stop',
        'list.select-next-row' => 'heroicon-o-bars-arrow-down',
        'list.select-prev-row' => 'heroicon-o-bars-arrow-up',
        'record.print' => 'heroicon-o-printer',
        'record.history' => 'heroicon-o-clock',
        'record.comment' => 'heroicon-o-chat-bubble-left-ellipsis',
        'record.archive' => 'heroicon-o-archive-box',
        'record.approve' => 'heroicon-o-hand-thumb-up',
        'record.reject' => 'heroicon-o-hand-thumb-down',
        'record.merge' => 'heroicon-o-arrows-pointing-in',
        'nav.goto' => 'heroicon-o-arrow-right-circle',
        'nav.dashboard' => 'heroicon-o-home',
        'nav.profile' => 'heroicon-o-user-circle',
        'nav.logout' => 'heroicon-o-arrow-right-start-on-rectangle',
        'nav.command-palette' => 'heroicon-o-command-line',
        'nav.recently-viewed' => 'heroicon-o-arrow-uturn-left',
        'nav.notifications' => 'heroicon-o-bell',
        'ui.help' => 'heroicon-o-question-mark-circle',
        'ui.close' => 'heroicon-o-x-circle',
        'ui.next-tab' => 'heroicon-o-arrow-right-circle',
        'ui.prev-tab' => 'heroicon-o-arrow-left-circle',
    ];

    protected const NAMESPACE_ICONS = [
        'crud' => 'heroicon-o-document-text',
        'list' => 'heroicon-o-table-cells',
        'record' => 'heroicon-o-document-text',
        'nav' => 'heroicon-o-rectangle-stack',
        'ui' => 'heroicon-o-window',
        'custom' => 'heroicon-o-puzzle-piece',
    ];

    /** Fixed category order used for table grouping/sorting. */
    public const CATEGORY_ORDER = ['crud', 'list', 'record', 'nav', 'ui', 'custom'];

    /**
     * Bindings that must never be taken away (action id => required combo).
     * Without a working Escape, users couldn't close modals or cancel anything.
     */
    public const PROTECTED_BINDINGS = ['ui.close' => 'escape'];

    public static function isProtected(string $actionId): bool
    {
        return array_key_exists($actionId, self::PROTECTED_BINDINGS);
    }

    public static function category(string $actionId): string
    {
        return explode('.', $actionId, 2)[0];
    }

    public static function categoryLabel(string $namespace): string
    {
        $key = 'filament-mouseless::mouseless.ns.' . $namespace;
        $label = __($key);

        return $label === $key ? Str::headline($namespace) : $label;
    }

    public static function label(string $actionId): string
    {
        // Custom (app-defined) actions carry their real label in the registry.
        if (str_starts_with($actionId, 'custom.')) {
            return static::customLabel($actionId)
                ?? Str::headline(Str::after($actionId, 'custom.'));
        }

        $key = 'filament-mouseless::mouseless.action.' . $actionId;
        $label = __($key);

        if ($label !== $key) {
            return $label;
        }

        // Dynamic ids (e.g. nav.resource.{slug}) have no translation entry.
        if (str_starts_with($actionId, 'nav.resource.')) {
            return Str::headline(Str::after($actionId, 'nav.resource.'));
        }

        return Str::headline(Str::afterLast($actionId, '.'));
    }

    protected static function customLabel(string $actionId): ?string
    {
        try {
            $meta = app(CustomActionRegistry::class)->all()[$actionId] ?? null;
        } catch (\Throwable) {
            return null;
        }

        $label = $meta['label'] ?? null;

        return (is_string($label) && $label !== '') ? $label : null;
    }

    public static function icon(string $actionId): string
    {
        return self::ICONS[$actionId]
            ?? self::NAMESPACE_ICONS[static::category($actionId)]
            ?? 'heroicon-o-cursor-arrow-ripple';
    }

    public static function categoryOrder(string $namespace): int
    {
        $index = array_search($namespace, self::CATEGORY_ORDER, true);

        return $index === false ? count(self::CATEGORY_ORDER) : $index;
    }
}
