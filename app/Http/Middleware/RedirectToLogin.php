<?php

namespace App\Http\Middleware;

use Filament\Http\Middleware\Authenticate as BaseAuthenticate;

class RedirectToLogin extends BaseAuthenticate
{
    protected function redirectTo($request): ?string
    {
        return url('/login');
    }
}
