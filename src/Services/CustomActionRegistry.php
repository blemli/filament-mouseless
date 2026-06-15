<?php

namespace Blemli\FilamentMouseless\Services;

use Blemli\FilamentMouseless\Support\Keys;

/**
 * Tracks the host app's custom Filament actions (those carrying
 * ->keyBindings()). Two sources are merged:
 *
 *   - the manifest file (config/mouseless/actions.php) written by
 *     `mouseless:scan` — always available, so actions show up in the overlay
 *     and my-shortcuts page even when the user isn't on the action's page;
 *   - runtime discovery during the current page render (see ActionDiscovery) —
 *     authoritative for the live label/keys and for "is this action on the
 *     current page" availability. Runtime wins per id.
 *
 * Request-scoped: the runtime store is rebuilt on every page render, and the
 * manifest is re-read per request (cheap — one file require).
 */
class CustomActionRegistry
{
    /** @var array<string, array<string, mixed>>|null Lazily-loaded manifest entries (raw). */
    private ?array $manifest = null;

    /** @var array<string, array<string, mixed>> Entries discovered while rendering the current page. */
    private array $runtime = [];

    public function flush(): void
    {
        $this->runtime = [];
        $this->manifest = null;
    }

    /**
     * Record an action discovered while rendering the current page.
     *
     * @param  array<string, mixed>  $meta  keys: label, keyBindings, managed, source
     */
    public function registerRuntime(string $id, array $meta): void
    {
        $this->runtime[$id] = [...($this->runtime[$id] ?? []), ...$meta, 'onPage' => true];
    }

    /**
     * Merged, normalized view of every known custom action.
     *
     * Each entry: [
     *   'label'   => ?string,
     *   'combos'  => array<int, string>,  // engine-normalized
     *   'managed' => bool,
     *   'onPage'  => bool,
     *   'source'  => ?string,
     * ]
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        $out = [];

        foreach ($this->manifest() as $id => $meta) {
            $out[$id] = $this->normalizeEntry($meta, false);
        }

        foreach ($this->runtime as $id => $meta) {
            $out[$id] = $this->normalizeEntry($meta, true);
        }

        return $out;
    }

    /**
     * Ids discovered on the current page (available right now).
     *
     * @return array<int, string>
     */
    public function currentPageIds(): array
    {
        return array_keys($this->runtime);
    }

    /**
     * Default bindings for managed actions, fed to the BindingResolver so the
     * engine fires them. id => first combo. Managed entries only.
     *
     * @return array<string, string>
     */
    public function managedDefaults(): array
    {
        $defaults = [];

        foreach ($this->all() as $id => $meta) {
            if (! ($meta['managed'] ?? false)) {
                continue;
            }

            $combo = $meta['combos'][0] ?? null;
            if ($combo !== null) {
                $defaults[$id] = $combo;
            }
        }

        return $defaults;
    }

    public function isEmpty(): bool
    {
        return $this->manifest() === [] && $this->runtime === [];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    protected function normalizeEntry(array $meta, bool $onPage): array
    {
        $raw = $meta['combos'] ?? $meta['keyBindings'] ?? [];
        if (is_string($raw)) {
            $raw = [$raw];
        }

        $combos = [];
        foreach ((array) $raw as $combo) {
            $normalized = Keys::fromMousetrap((string) $combo);
            if ($normalized !== null) {
                $combos[] = $normalized;
            }
        }

        return [
            'label' => $meta['label'] ?? null,
            'combos' => array_values(array_unique($combos)),
            'managed' => (bool) ($meta['managed'] ?? false),
            'onPage' => $onPage || (bool) ($meta['onPage'] ?? false),
            'source' => $meta['source'] ?? null,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function manifest(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        $path = config('mouseless.custom_actions_path');

        if (! is_string($path) || ! is_file($path)) {
            return $this->manifest = [];
        }

        try {
            $data = require $path;
        } catch (\Throwable) {
            return $this->manifest = [];
        }

        return $this->manifest = is_array($data) ? $data : [];
    }
}
