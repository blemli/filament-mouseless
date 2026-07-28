<?php

use Blemli\FilamentMouseless\Enums\MouselessAction;
use Blemli\FilamentMouseless\FilamentMouselessPlugin;
use Blemli\FilamentMouseless\Services\BindingResolver;
use Blemli\FilamentMouseless\Support\Keys;

it('overrides a default binding via config remap', function () {
    config()->set('mouseless.remap', ['crud.create' => 'alt+shift+n']);

    $resolved = app(BindingResolver::class)->forUser(null);

    expect($resolved['bindings']['crud.create'])->toBe('alt+shift+n');
});

it('applies the remap to every built-in preset', function () {
    config()->set('mouseless.remap', ['crud.create' => 'alt+shift+n']);
    app()->setLocale('de');

    $resolved = app(BindingResolver::class)->forUser(null);

    expect($resolved['preset']['slug'])->toBe('german-default')
        ->and($resolved['bindings']['crud.create'])->toBe('alt+shift+n');
});

it('unbinds an action when the remap combo is null or empty', function () {
    config()->set('mouseless.remap', [
        'crud.delete' => null,
        'crud.edit' => '',
    ]);

    $resolved = app(BindingResolver::class)->forUser(null);

    expect($resolved['bindings'])->not->toHaveKey('crud.delete')
        ->and($resolved['bindings'])->not->toHaveKey('crud.edit');
});

it('translates combo aliases without resolving mod', function () {
    config()->set('mouseless.remap', [
        'crud.edit' => 'opt+z',
        'crud.save' => 'mod+shift+s',
    ]);

    $resolved = app(BindingResolver::class)->forUser(null);

    expect($resolved['bindings']['crud.edit'])->toBe('alt+z')
        ->and($resolved['bindings']['crud.save'])->toBe('mod+shift+s');
});

it('never remaps protected bindings', function () {
    config()->set('mouseless.remap', ['ui.close' => null]);

    $resolved = app(BindingResolver::class)->forUser(null);

    expect($resolved['bindings']['ui.close'])->toBe('Escape');
});

it('accepts MouselessAction enum cases in the fluent api', function () {
    $plugin = FilamentMouselessPlugin::make()
        ->remap(MouselessAction::Create, 'alt+shift+n')
        ->remap('record.reject', null);

    expect($plugin->getRemaps())->toBe([
        'crud.create' => 'alt+shift+n',
        'record.reject' => null,
    ]);
});

it('translates opt and option aliases to alt', function () {
    expect(Keys::translateAliases('opt+x'))->toBe('alt+x')
        ->and(Keys::translateAliases('option+shift+p'))->toBe('alt+shift+p')
        ->and(Keys::translateAliases('mod+Enter'))->toBe('mod+enter')
        ->and(Keys::translateAliases(''))->toBeNull()
        ->and(Keys::translateAliases(null))->toBeNull();
});
