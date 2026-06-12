<?php

namespace Blemli\FilamentMouseless;

use Blemli\FilamentMouseless\Services\BindingResolver;
use Blemli\FilamentMouseless\Services\PresetRegistry;

class FilamentMouseless
{
    public function resolver(): BindingResolver
    {
        return app(BindingResolver::class);
    }

    public function registry(): PresetRegistry
    {
        return app(PresetRegistry::class);
    }

    public function forCurrentUser(): array
    {
        return $this->resolver()->forUser(auth()->id());
    }

    /** Drop the request-scoped resolution memos after a layout mutation. */
    public function flush(): void
    {
        $this->resolver()->flush();
        $this->registry()->flush();
    }
}
