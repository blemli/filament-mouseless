<?php

use Blemli\FilamentMouseless\Filament\Concerns\MouselessKeyBindings;
use Blemli\FilamentMouseless\Services\ActionDiscovery;
use Blemli\FilamentMouseless\Services\CustomActionRegistry;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Log;

class DiscoveryManagedPage extends Page
{
    use MouselessKeyBindings;

    protected string $view = 'filament-mouseless::pages.my-shortcuts';

    /** @var array<int, Action> */
    public array $headerActions = [];

    public function getCachedHeaderActions(): array
    {
        return $this->headerActions;
    }

    public function getCachedFormActions(): array
    {
        return [];
    }
}

class DiscoveryNativePage extends Page
{
    protected string $view = 'filament-mouseless::pages.my-shortcuts';

    /** @var array<int, Action> */
    public array $headerActions = [];

    public function getCachedHeaderActions(): array
    {
        return $this->headerActions;
    }

    public function getCachedFormActions(): array
    {
        return [];
    }
}

it('takes over a custom action on a page that uses the trait', function () {
    $action = Action::make('approve')->keyBindings(['mod+shift+a']);

    $page = new DiscoveryManagedPage;
    $page->headerActions = [$action];

    app(ActionDiscovery::class)->discover($page);

    $registry = app(CustomActionRegistry::class)->all();

    expect($registry)->toHaveKey('custom.approve')
        ->and($registry['custom.approve']['managed'])->toBeTrue()
        ->and($action->getKeyBindings())->toBeNull()
        ->and($action->getExtraAttributes())->toHaveKey('data-mouseless')
        ->and($action->getExtraAttributes()['data-mouseless'])->toBe('custom.approve');
});

it('keeps a custom action read-only without the trait and warns', function () {
    Log::spy();

    $action = Action::make('export')->keyBindings(['mod+e']);

    $page = new DiscoveryNativePage;
    $page->headerActions = [$action];

    app(ActionDiscovery::class)->discover($page);

    $registry = app(CustomActionRegistry::class)->all();

    expect($registry)->toHaveKey('custom.export')
        ->and($registry['custom.export']['managed'])->toBeFalse()
        ->and($action->getKeyBindings())->toBe(['mod+e'])
        ->and($action->getExtraAttributes())->not->toHaveKey('data-mouseless');

    Log::shouldHaveReceived('warning')->once();
});

it('skips core action names', function () {
    $action = Action::make('save')->keyBindings(['mod+s']);

    $page = new DiscoveryManagedPage;
    $page->headerActions = [$action];

    app(ActionDiscovery::class)->discover($page);

    expect(app(CustomActionRegistry::class)->all())->not->toHaveKey('custom.save')
        ->and($action->getKeyBindings())->toBe(['mod+s']);
});
