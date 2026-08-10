<?php

namespace Blemli\FilamentMouseless\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A user's shortcut layout was persisted through the shortcuts table —
 * rebind, remove, reset or enable/disable, single, bulk or per-category.
 * Not fired for preset-level operations (switch, publish, import, …);
 * see the Preset* events for those.
 */
class ShortcutsChanged
{
    use Dispatchable;

    /**
     * @param  array<string, ?string>  $oldBindings
     * @param  array<string, ?string>  $newBindings
     * @param  array<int, string>  $oldDisabled
     * @param  array<int, string>  $newDisabled
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $presetSlug,
        public readonly array $oldBindings,
        public readonly array $newBindings,
        public readonly array $oldDisabled,
        public readonly array $newDisabled,
    ) {}

    /**
     * Action ids whose binding or enabled state differs between old and new.
     *
     * @return array<int, string>
     */
    public function changedActionIds(): array
    {
        $ids = [];

        foreach (array_unique([...array_keys($this->oldBindings), ...array_keys($this->newBindings)]) as $id) {
            if (($this->oldBindings[$id] ?? null) !== ($this->newBindings[$id] ?? null)) {
                $ids[] = $id;
            }
        }

        $flipped = [
            ...array_diff($this->oldDisabled, $this->newDisabled),
            ...array_diff($this->newDisabled, $this->oldDisabled),
        ];

        return array_values(array_unique([...$ids, ...$flipped]));
    }
}
