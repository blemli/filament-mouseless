<?php

namespace Blemli\FilamentMouseless\Models;

use Blemli\FilamentMouseless\Events\PresetCreated;
use Blemli\FilamentMouseless\Events\PresetDeleted;
use Blemli\FilamentMouseless\Services\PresetRegistry;
use Blemli\FilamentMouseless\Support\PanelAuth;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Preset extends Model
{
    protected $table = 'mouseless_presets';

    protected $guarded = [];

    protected $dispatchesEvents = [
        'created' => PresetCreated::class,
        'deleted' => PresetDeleted::class,
    ];

    protected $casts = [
        'bindings' => 'array',
        'disabled_actions' => 'array',
        'is_published' => 'bool',
        'published_at' => 'datetime',
        'approved_at' => 'datetime',
        // Strict comparisons against PanelAuth::id() decide the lock/fork flow —
        // some drivers return numeric strings without this cast.
        'owner_user_id' => 'integer',
    ];

    public function isApproved(): bool
    {
        return $this->is_published && $this->approved_at !== null;
    }

    /**
     * Create a personal layout for the current user as a copy of $source
     * (a registry preset array or import payload). Single factory for the
     * fork / create-layout / import paths.
     */
    public static function forkFrom(array $source, string $name, ?string $description = null, string $origin = 'user'): self
    {
        $name = static::uniqueNameForUser($name);

        return static::create([
            'slug' => static::uniqueSlug($name),
            'name' => $name,
            'description' => $description,
            'locale' => $source['locale'] ?? app()->getLocale(),
            'version' => $source['version'] ?? '1.0',
            'owner_user_id' => PanelAuth::id(),
            'source' => $origin,
            'parent_slug' => $source['slug'] ?? null,
            'bindings' => (array) ($source['bindings'] ?? []),
            'disabled_actions' => array_values((array) ($source['disabled_actions'] ?? [])),
        ]);
    }

    /**
     * Proposed name for a new personal layout: „{Vorname}s Layout" — or the
     * first free numbered variant („… 2", „… 3", …) if it's already taken.
     */
    public static function defaultLayoutName(): string
    {
        $firstName = str(PanelAuth::user()?->name ?? 'My')->before(' ')->toString();

        return static::uniqueNameForUser(
            __('filament-mouseless::mouseless.table.fork.name', ['name' => $firstName]),
        );
    }

    public static function isNameTakenForUser(string $name, ?string $ignoreSlug = null): bool
    {
        return static::query()
            ->where('owner_user_id', PanelAuth::id())
            ->where('name', $name)
            ->when($ignoreSlug, fn ($query) => $query->where('slug', '!=', $ignoreSlug))
            ->exists();
    }

    /** $base, or the first free numbered variant ("… 2", "… 3"), unique among the user's layouts. */
    public static function uniqueNameForUser(string $base, ?string $ignoreSlug = null): string
    {
        $base = trim($base);

        if (! static::isNameTakenForUser($base, $ignoreSlug)) {
            return $base;
        }

        // Strip an existing trailing number so suffixes never stack
        // ("Layout 3" → counts on from "Layout", not "Layout 3 2").
        $root = trim(preg_replace('/\s+\d+$/', '', $base)) ?: $base;

        for ($i = 2; ; $i++) {
            if (! static::isNameTakenForUser("{$root} {$i}", $ignoreSlug)) {
                return "{$root} {$i}";
            }
        }
    }

    /** Slug derived from $name that collides with no DB preset and no built-in. */
    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'layout';
        $slug = $base;

        while (
            static::query()->where('slug', $slug)->exists()
            || array_key_exists($slug, app(PresetRegistry::class)->builtIn())
        ) {
            $slug = $base . '-' . Str::lower(Str::random(4));
        }

        return $slug;
    }
}
