<?php

use Blemli\FilamentMouseless\Services\CustomActionRegistry;

beforeEach(function () {
    $this->manifestPath = sys_get_temp_dir() . '/mouseless-manifest-' . uniqid() . '.php';
    config()->set('mouseless.custom_actions_path', $this->manifestPath);
});

afterEach(function () {
    @unlink($this->manifestPath);
});

function writeManifest(string $path, array $entries): void
{
    file_put_contents($path, '<?php return ' . var_export($entries, true) . ';');
}

it('reads and normalizes the manifest', function () {
    writeManifest($this->manifestPath, [
        'custom.approve' => [
            'label' => 'Approve',
            'keyBindings' => ['mod+shift+a'],
            'managed' => true,
            'scanned' => true,
        ],
    ]);

    $registry = new CustomActionRegistry;
    $all = $registry->all();

    expect($all)->toHaveKey('custom.approve')
        ->and($all['custom.approve']['label'])->toBe('Approve')
        ->and($all['custom.approve']['managed'])->toBeTrue()
        ->and($all['custom.approve']['onPage'])->toBeFalse()
        ->and($all['custom.approve']['combos'])->toBe(['ctrl+shift+a']); // non-mac
});

it('lets runtime entries win over the manifest and marks them on-page', function () {
    writeManifest($this->manifestPath, [
        'custom.approve' => ['label' => 'Old', 'keyBindings' => ['mod+a'], 'managed' => false, 'scanned' => true],
    ]);

    $registry = new CustomActionRegistry;
    $registry->registerRuntime('custom.approve', [
        'label' => 'Approve now',
        'keyBindings' => ['mod+shift+a'],
        'managed' => true,
    ]);

    $all = $registry->all();

    expect($all['custom.approve']['label'])->toBe('Approve now')
        ->and($all['custom.approve']['managed'])->toBeTrue()
        ->and($all['custom.approve']['onPage'])->toBeTrue()
        ->and($registry->currentPageIds())->toBe(['custom.approve']);
});

it('exposes managed defaults only', function () {
    $registry = new CustomActionRegistry;
    $registry->registerRuntime('custom.managed', ['keyBindings' => ['mod+m'], 'managed' => true]);
    $registry->registerRuntime('custom.readonly', ['keyBindings' => ['mod+r'], 'managed' => false]);

    expect($registry->managedDefaults())->toBe(['custom.managed' => 'ctrl+m']);
});

it('tolerates a missing manifest file', function () {
    $registry = new CustomActionRegistry;

    expect($registry->all())->toBe([])
        ->and($registry->isEmpty())->toBeTrue();
});
