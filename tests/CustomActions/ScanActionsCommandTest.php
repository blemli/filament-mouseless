<?php

use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->scanDir = sys_get_temp_dir() . '/mouseless-scan-' . uniqid();
    $this->manifestPath = sys_get_temp_dir() . '/mouseless-actions-' . uniqid() . '.php';

    File::ensureDirectoryExists($this->scanDir);

    config()->set('mouseless.scan_paths', [$this->scanDir]);
    config()->set('mouseless.custom_actions_path', $this->manifestPath);
});

afterEach(function () {
    File::deleteDirectory($this->scanDir);
    @unlink($this->manifestPath);
});

function writeScanFixture(string $dir, string $name, string $body): void
{
    File::put("{$dir}/{$name}.php", "<?php\n\nnamespace App\\Filament;\n\n{$body}\n");
}

it('discovers keyBindings and writes the manifest', function () {
    writeScanFixture($this->scanDir, 'EditOrder', <<<'PHP'
        use Blemli\FilamentMouseless\Filament\Concerns\MouselessKeyBindings;

        class EditOrder
        {
            use MouselessKeyBindings;

            public function action()
            {
                return Action::make('approve')->keyBindings(['mod+shift+a']);
            }
        }
        PHP);

    writeScanFixture($this->scanDir, 'ListThings', <<<'PHP'
        class ListThings
        {
            public function action()
            {
                return Action::make('export')->keyBindings(['mod+e']);
            }
        }
        PHP);

    $this->artisan('mouseless:scan')->assertSuccessful();

    $manifest = require $this->manifestPath;

    expect($manifest)->toHaveKeys(['custom.approve', 'custom.export'])
        ->and($manifest['custom.approve']['managed'])->toBeTrue()
        ->and($manifest['custom.approve']['keyBindings'])->toBe(['mod+shift+a'])
        ->and($manifest['custom.approve']['scanned'])->toBeTrue()
        ->and($manifest['custom.approve']['source'])->toBe('App\Filament\EditOrder')
        ->and($manifest['custom.export']['managed'])->toBeFalse();
});

it('records dynamic keyBindings with a null combo', function () {
    writeScanFixture($this->scanDir, 'DynamicPage', <<<'PHP'
        class DynamicPage
        {
            public function action()
            {
                return Action::make('dyn')->keyBindings(fn () => ['mod+d']);
            }
        }
        PHP);

    $this->artisan('mouseless:scan')->assertSuccessful();

    $manifest = require $this->manifestPath;

    expect($manifest['custom.dyn']['keyBindings'])->toBeNull();
});

it('preserves manual entries and prunes removed scanned ones', function () {
    writeScanFixture($this->scanDir, 'ListThings', <<<'PHP'
        class ListThings
        {
            public function action()
            {
                return Action::make('export')->keyBindings(['mod+e']);
            }
        }
        PHP);

    File::put($this->manifestPath, '<?php return ' . var_export([
        'custom.manual' => [
            'label' => 'Manual',
            'keyBindings' => ['mod+m'],
            'managed' => true,
            'source' => null,
            'scanned' => false,
        ],
    ], true) . ';');

    $this->artisan('mouseless:scan')->assertSuccessful();

    $manifest = require $this->manifestPath;
    expect($manifest)->toHaveKeys(['custom.manual', 'custom.export']);

    // Remove the scanned source and re-scan: the manual entry survives, the
    // scanned one is pruned.
    File::delete("{$this->scanDir}/ListThings.php");
    $this->artisan('mouseless:scan')->assertSuccessful();

    $manifest = require $this->manifestPath;
    expect($manifest)->toHaveKey('custom.manual')
        ->and($manifest)->not->toHaveKey('custom.export');
});

it('ignores core action names', function () {
    writeScanFixture($this->scanDir, 'EditPost', <<<'PHP'
        class EditPost
        {
            public function action()
            {
                return Action::make('save')->keyBindings(['mod+s']);
            }
        }
        PHP);

    $this->artisan('mouseless:scan')->assertSuccessful();

    $manifest = is_file($this->manifestPath) ? require $this->manifestPath : [];

    expect($manifest)->not->toHaveKey('custom.save');
});
