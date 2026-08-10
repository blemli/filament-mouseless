<?php

namespace Blemli\FilamentMouseless\Support;

use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;

/**
 * The authenticated user through the current panel's auth guard. Panels can
 * run different guards, so the framework helper auth() — always the default
 * guard — would resolve nobody (or somebody else) on such a panel. Outside
 * a panel context (CLI, queued listeners) Filament has no current panel and
 * we fall back to the default guard.
 */
class PanelAuth
{
    public static function user(): ?Authenticatable
    {
        return static::guard()->user();
    }

    public static function id(): int | string | null
    {
        return static::guard()->id();
    }

    public static function check(): bool
    {
        return static::guard()->check();
    }

    protected static function guard(): Guard
    {
        try {
            return Filament::auth();
        } catch (\Throwable) {
            return auth()->guard();
        }
    }
}
