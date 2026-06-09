<?php

namespace Blemli\FilamentMouseless\Services;

use Blemli\FilamentMouseless\Models\Preset;

class PresetRegistry
{
    /**
     * Return every preset visible to the given user: built-ins,
     * the user's personal presets, and installation-published ones.
     *
     * @return array<string, array> Keyed by slug.
     */
    public function all(?int $userId = null): array
    {
        $presets = $this->builtIn();

        if ($userId !== null) {
            foreach (Preset::query()->where('owner_user_id', $userId)->get() as $p) {
                $presets[$p->slug] = $this->modelToArray($p);
            }
        }

        if (config('mouseless.publishing.enabled')) {
            foreach (Preset::query()->where('is_published', true)->get() as $p) {
                if (! $this->isApprovedIfRequired($p)) {
                    continue;
                }
                $presets[$p->slug] ??= $this->modelToArray($p);
            }
        }

        return $presets;
    }

    public function find(?string $slug, ?int $userId = null): ?array
    {
        if ($slug === null || $slug === '') {
            return null;
        }

        return $this->all($userId)[$slug] ?? null;
    }

    /**
     * @return array<string, array>
     */
    public function builtIn(): array
    {
        $presets = [];

        // Always scan the package's bundled presets (lives next to this file).
        $packagePath = dirname(__DIR__, 2) . '/config/mouseless/presets';
        $this->loadInto($presets, $packagePath);

        // Optionally also scan a user-defined path (config) for extra built-ins.
        $userPath = config('mouseless.presets_path');
        if (is_string($userPath) && $userPath !== $packagePath) {
            $this->loadInto($presets, $userPath);
        }

        return $presets;
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
            'slug' => $p->slug,
            'name' => $p->name,
            'locale' => $p->locale,
            'version' => $p->version,
            'author' => $p->owner_user_id ? "user:{$p->owner_user_id}" : 'installation',
            'bindings' => $p->bindings ?? [],
            'panels' => $p->panels,
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
