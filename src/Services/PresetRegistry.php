<?php

namespace Blemli\FilamentMouseless\Services;

use Blemli\FilamentMouseless\FilamentMouselessPlugin;
use Blemli\FilamentMouseless\Models\Preset;
use Filament\Facades\Filament;

class PresetRegistry
{
    /** @var ?array<string, array> Built-ins come from config files — immutable per request. */
    private ?array $builtInCache = null;

    /** @var array<string, array<string, array>> Request-scoped memo — flushed after layout mutations. */
    private array $allCache = [];

    public function flush(): void
    {
        $this->allCache = [];
    }

    /**
     * Return every preset visible to the given user: built-ins,
     * the user's personal presets, and installation-published ones.
     *
     * @return array<string, array> Keyed by slug.
     */
    public function all(?int $userId = null): array
    {
        // The tenant is part of the memo key: published layouts can be
        // tenant-scoped, so the same user sees different sets per tenant.
        $key = ($userId ?? 0) . '@' . static::tenantKey();

        return $this->allCache[$key] ??= $this->load($userId);
    }

    /**
     * @return array<string, array>
     */
    protected function load(?int $userId): array
    {
        $presets = $this->builtIn();

        if ($userId !== null) {
            foreach (Preset::query()->where('owner_user_id', $userId)->get() as $p) {
                $presets[$p->slug] = $this->modelToArray($p);
            }
        }

        if (FilamentMouselessPlugin::publishingEnabled()) {
            $published = Preset::query()->where('is_published', true);

            // Tenant-scoped publishing: only layouts published under the
            // current tenant, plus tenant-less ones — those were published
            // outside any tenant context and stay installation-wide.
            if (FilamentMouselessPlugin::publishingScopedToTenant()) {
                $tenant = static::tenantKey();
                $published->where(fn ($query) => $query
                    ->whereNull('tenant_id')
                    ->when($tenant !== '', fn ($query) => $query->orWhere('tenant_id', $tenant)));
            }

            foreach ($published->get() as $p) {
                if (! $this->isApprovedIfRequired($p)) {
                    continue;
                }
                $presets[$p->slug] ??= $this->modelToArray($p);
            }
        }

        return $presets;
    }

    /** The current Filament tenant's key as a string, '' when there is none. */
    protected static function tenantKey(): string
    {
        return (string) (Filament::getTenant()?->getKey() ?? '');
    }

    public function find(?string $slug, ?int $userId = null): ?array
    {
        if ($slug === null || $slug === '') {
            return null;
        }

        return $this->all($userId)[$slug] ?? null;
    }

    /** The built-in preset matching the given locale (e.g. 'de' → german-default). */
    public function builtInForLocale(string $locale): ?array
    {
        foreach ($this->builtIn() as $preset) {
            if (($preset['locale'] ?? null) === $locale) {
                return $preset;
            }
        }

        return null;
    }

    /**
     * @return array<string, array>
     */
    public function builtIn(): array
    {
        if ($this->builtInCache !== null) {
            return $this->builtInCache;
        }

        $presets = [];

        // Always scan the package's bundled presets (lives next to this file).
        $packagePath = dirname(__DIR__, 2) . '/config/mouseless/presets';
        $this->loadInto($presets, $packagePath);

        // Optionally also scan a user-defined path (config) for extra built-ins.
        $userPath = config('mouseless.presets_path');
        if (is_string($userPath) && $userPath !== $packagePath) {
            $this->loadInto($presets, $userPath);
        }

        // Dev remaps (config `mouseless.remap` / plugin ->remap()) rewrite the
        // built-in defaults themselves, so every locale's preset, the overlay,
        // the cheatsheet and /my-shortcuts all agree. User forks sit nearer in
        // the resolution chain and still win where they explicitly rebound.
        $overrides = FilamentMouselessPlugin::remapOverrides();
        if ($overrides !== []) {
            foreach ($presets as $slug => $preset) {
                $presets[$slug]['bindings'] = array_merge((array) ($preset['bindings'] ?? []), $overrides);
            }
        }

        return $this->builtInCache = $presets;
    }

    protected function loadInto(array &$presets, string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        foreach (glob(rtrim($path, '/') . '/*.php') as $file) {
            $preset = require $file;
            if (! is_array($preset) || ! isset($preset['slug'])) {
                continue;
            }
            $preset['source'] = 'builtin';
            $presets[$preset['slug']] = $preset;
        }
    }

    protected function modelToArray(Preset $p): array
    {
        return [
            'id' => $p->getKey(),
            'slug' => $p->slug,
            'name' => $p->name,
            'description' => $p->description,
            'locale' => $p->locale,
            'version' => $p->version,
            'author' => $p->owner_user_id ? "user:{$p->owner_user_id}" : 'installation',
            'owner_user_id' => $p->owner_user_id,
            'parent_slug' => $p->parent_slug,
            'bindings' => $p->bindings ?? [],
            'disabled_actions' => $p->disabled_actions ?? [],
            'source' => $p->source,
        ];
    }

    protected function isApprovedIfRequired(Preset $p): bool
    {
        if (! config('mouseless.publishing.require_approval')) {
            return true;
        }

        return $p->isApproved();
    }
}
