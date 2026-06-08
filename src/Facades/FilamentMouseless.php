<?php

namespace Blemli\FilamentMouseless\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Blemli\FilamentMouseless\FilamentMouseless
 */
class FilamentMouseless extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Blemli\FilamentMouseless\FilamentMouseless::class;
    }
}
