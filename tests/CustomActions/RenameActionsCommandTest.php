<?php

use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->renameDir = sys_get_temp_dir() . '/mouseless-rename-' . uniqid();
    File::ensureDirectoryExists($this->renameDir);
    config()->set('mouseless.scan_paths', [$this->renameDir]);
});

afterEach(function () {
    File::deleteDirectory($this->renameDir);
});

function writeRenameFixture(string $dir, string $name, string $body): string
{
    $path = "{$dir}/{$name}.php";
    File::put($path, "<?php\n\nnamespace App\\Filament;\n\n{$body}\n");

    return $path;
}

it('plans renames without touching files by default', function () {
    $path = writeRenameFixture($this->renameDir, 'ListOrders', <<<'PHP'
        class ListOrders
        {
            public function actions()
            {
                return [
                    Action::make('remove'),
                    Tables\Actions\Action::make('copy'),
                    Action::make('delete'), // already canonical
                ];
            }
        }
        PHP);

    $this->artisan('mouseless:rename-actions')
        ->expectsOutputToContain('remove → delete')
        ->expectsOutputToContain('copy → replicate')
        ->expectsOutputToContain('2 rename(s) planned')
        ->assertSuccessful();

    expect(File::get($path))->toContain("Action::make('remove')");
});

it('applies renames with --write and flags leftover references', function () {
    $path = writeRenameFixture($this->renameDir, 'EditPost', <<<'PHP'
        class EditPost
        {
            public function actions()
            {
                return [Action::make('accept'), BulkAction::make('decline')];
            }

            public function other()
            {
                return $this->getAction('accept');
            }
        }
        PHP);

    $this->artisan('mouseless:rename-actions', ['--write' => true])
        ->expectsOutputToContain('accept → approve')
        ->expectsOutputToContain('decline → reject')
        ->expectsOutputToContain("other reference(s) to 'accept'")
        ->assertSuccessful();

    $source = File::get($path);
    expect($source)->toContain("Action::make('approve')")
        ->toContain("BulkAction::make('reject')")
        ->toContain("getAction('accept')"); // untouched, flagged for review
});

it('honours a config-extended rename map', function () {
    config()->set('mouseless.rename_map', ['freigeben' => 'share']);

    writeRenameFixture($this->renameDir, 'ViewDoc', <<<'PHP'
        class ViewDoc
        {
            public function actions()
            {
                return [Action::make('freigeben')];
            }
        }
        PHP);

    $this->artisan('mouseless:rename-actions')
        ->expectsOutputToContain('freigeben → share')
        ->assertSuccessful();
});

it('reports a clean scheme', function () {
    writeRenameFixture($this->renameDir, 'Clean', <<<'PHP'
        class Clean
        {
            public function actions()
            {
                return [Action::make('approve'), Action::make('history')];
            }
        }
        PHP);

    $this->artisan('mouseless:rename-actions')
        ->expectsOutputToContain('nothing to rename')
        ->assertSuccessful();
});
