<?php

namespace Blemli\FilamentMouseless\Support;

use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use Blemli\FilamentMouseless\FilamentMouselessPlugin;
use Illuminate\Support\Str;

/**
 * Optional adapter for bezhansalleh/filament-shield. Permission gates are
 * off by default — callers see "allowed" unless the plugin is in strict
 * mode AND Shield denies. Strict mode is opted into via the plugin's
 * `->strictPermissions()` method.
 */
class Shield
{
    /**
     * Slug we register with Shield. Shield re-formats it to match the
     * consumer's configured case before seeding — the runtime key callers
     * compare against is whatever {@see permission()} returns.
     */
    public const CUSTOM_PERMISSION = 'mouseless_use';

    public static function isInstalled(): bool
    {
        return class_exists(FilamentShield::class);
    }

    /** True only when the plugin is registered on the current panel in strict mode. */
    public static function isStrict(): bool
    {
        try {
            return FilamentMouselessPlugin::get()->isStrict();
        } catch (\Throwable) {
            return false;
        }
    }

    /** The "use mouseless at all" permission key, case-formatted per Shield config. */
    public static function permission(): string
    {
        return static::formatKey(self::CUSTOM_PERMISSION);
    }

    /** Translated label used when registering the custom permission with Shield. */
    public static function permissionLabel(): string
    {
        return __('filament-mouseless::mouseless.shield.permissions.mouseless_use');
    }

    /** Master switch. Permissive (true) unless strict mode is on AND Shield denies. */
    public static function userMayUse(): bool
    {
        return ! static::isStrict() || static::userCan(static::permission());
    }

    /**
     * Combined gate for a mouseless page: master switch + page permission.
     * Always allowed in permissive (default) mode — strict mode opts in.
     */
    public static function userCanAccessPage(string $pageClass): bool
    {
        if (! static::isStrict()) {
            return true;
        }

        if (! static::userCan(static::permission())) {
            return false;
        }

        $pagePermission = static::pagePermission($pageClass);

        return $pagePermission === null || static::userCan($pagePermission);
    }

    /**
     * Shield's page-permission key for the given class via Shield's own
     * discovery, or null when Shield isn't installed / the page is excluded.
     */
    public static function pagePermission(string $pageClass): ?string
    {
        if (! static::isInstalled()) {
            return null;
        }

        try {
            $entry = (FilamentShield::getPages() ?? [])[$pageClass] ?? null;
        } catch (\Throwable) {
            return null;
        }

        return $entry && ! empty($entry['permissions'])
            ? array_key_first($entry['permissions'])
            : null;
    }

    /**
     * Check a Shield permission. Fail-open when Shield isn't installed or
     * spatie/permission tables aren't yet migrated — a half-installed Shield
     * shouldn't lock everyone out of the plugin.
     */
    public static function userCan(?string $permission): bool
    {
        if (! $permission || ! static::isInstalled()) {
            return true;
        }

        $user = auth()->user();
        if (! $user) {
            return false;
        }

        try {
            return (bool) $user->can($permission);
        } catch (\Throwable) {
            return true;
        }
    }

    /** Mirror Shield's `format()` (HasEntityTransformers) for permission-name case. */
    protected static function formatKey(string $key): string
    {
        return match (config('filament-shield.permissions.case', 'pascal')) {
            'kebab' => Str::kebab($key),
            'pascal' => Str::studly($key),
            'camel' => Str::camel($key),
            'upper_snake' => Str::upper(Str::snake($key)),
            'lower_snake' => Str::lower(Str::snake($key)),
            default => Str::snake($key),
        };
    }
}
