<?php

namespace Blemli\FilamentMouseless\Services;

use Blemli\FilamentMouseless\Filament\Concerns\MouselessKeyBindings;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Pages\Page;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Walks a Filament page's actions at render time, surfacing every custom
 * action that carries ->keyBindings():
 *
 *   - on pages using the MouselessKeyBindings trait (or with
 *     mouseless.manage_all_keybindings), it disarms the native binding and
 *     hands the action over to the engine via a data-mouseless attribute;
 *   - otherwise it registers the action read-only and logs a one-shot hint.
 *
 * Runs inside a Livewire `render` listener, so it must never throw — a failure
 * here would break the page it is inspecting.
 */
class ActionDiscovery
{
    /** Action names already represented by core crud.* / list.* ids. */
    protected const CORE_ACTION_NAMES = [
        'create', 'edit', 'view', 'delete', 'save', 'cancel',
        'replicate', 'restore', 'forceDelete',
    ];

    /** @var array<string, true> Per-request guard so each unmanaged action is logged once. */
    protected array $logged = [];

    public function __construct(protected CustomActionRegistry $registry) {}

    public function discover(object $component): void
    {
        if (! $component instanceof Page) {
            return;
        }

        try {
            $manageAll = (bool) config('mouseless.manage_all_keybindings', false);
            $usesTrait = in_array(MouselessKeyBindings::class, class_uses_recursive($component), true);
            $excluded = ($usesTrait && method_exists($component, 'mouselessExcludedActions'))
                ? (array) $component->mouselessExcludedActions()
                : [];

            foreach ($this->collectActions($component) as $action) {
                $this->handle($action, $component, $usesTrait || $manageAll, $excluded);
            }
        } catch (\Throwable $e) {
            // Discovery is best-effort and must never break a page render.
            Log::debug('[filament-mouseless] action discovery failed: ' . $e->getMessage());
        }
    }

    /**
     * @return array<int, Action>
     */
    protected function collectActions(Page $component): array
    {
        $actions = [];

        if (method_exists($component, 'getCachedHeaderActions')) {
            $actions = [...$actions, ...$this->flatten($component->getCachedHeaderActions())];
        }

        if (method_exists($component, 'getCachedFormActions')) {
            $actions = [...$actions, ...$this->flatten($component->getCachedFormActions())];
        }

        if ($component instanceof HasTable) {
            try {
                $actions = [...$actions, ...array_values($component->getTable()->getFlatActions())];
            } catch (\Throwable) {
                // The page has no built table; nothing to collect there.
            }
        }

        return $actions;
    }

    /**
     * @param  array<int, Action|ActionGroup>  $items
     * @return array<int, Action>
     */
    protected function flatten(array $items): array
    {
        $out = [];

        foreach ($items as $item) {
            if ($item instanceof ActionGroup) {
                $out = [...$out, ...array_values($item->getFlatActions())];
            } elseif ($item instanceof Action) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * @param  array<int, string>  $excluded
     */
    protected function handle(Action $action, Page $component, bool $takeover, array $excluded): void
    {
        $name = $action->getName();
        if ($name === null || in_array($name, self::CORE_ACTION_NAMES, true)) {
            return;
        }

        try {
            $bindings = $action->getKeyBindings();
        } catch (\Throwable) {
            return;
        }

        if (empty($bindings)) {
            return;
        }

        $id = 'custom.' . $name;
        $managed = $takeover && ! in_array($name, $excluded, true);

        $this->registry->registerRuntime($id, [
            'label' => $this->labelFor($action, $name),
            'keyBindings' => $bindings,
            'managed' => $managed,
            'source' => $component::class,
        ]);

        if ($managed) {
            // Strip the native x-mousetrap binding (the blade reads this same
            // cached instance right after) and let the engine click the button.
            $action->keyBindings(null);
            $action->extraAttributes(['data-mouseless' => $id], merge: true);

            return;
        }

        $this->warnUnmanaged($id, $name, $component::class);
    }

    protected function labelFor(Action $action, string $name): string
    {
        try {
            $label = $action->getLabel();
        } catch (\Throwable) {
            $label = null;
        }

        return (is_string($label) && $label !== '') ? $label : (string) Str::headline($name);
    }

    protected function warnUnmanaged(string $id, string $name, string $class): void
    {
        if (isset($this->logged[$id])) {
            return;
        }

        $this->logged[$id] = true;

        Log::warning(sprintf(
            "[filament-mouseless] Action '%s' on %s uses ->keyBindings() but the page lacks the MouselessKeyBindings trait — the shortcut is shown read-only and cannot be rebound by users. Add the trait to make it manageable.",
            $name,
            $class,
        ));
    }
}
