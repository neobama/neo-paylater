<?php

namespace App\Providers\Filament;

use App\Filament\Auth\Login;
use App\Filament\Pages\Overview;
use App\Http\Middleware\RedirectToLogin;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->darkMode(false)
            ->login(Login::class)
            ->brandName('Neo Paylater')
            ->favicon('/favicon.ico')
            ->brandLogo(fn (): HtmlString => new HtmlString(
                '<span class="neo-brand-logo">'.
                '<img class="neo-brand-logo__horizontal" src="/branding/neopaylater.png" alt="Neo Paylater" />'.
                '<img class="neo-brand-logo__square" src="/branding/neopaylater.png" alt="Neo Paylater" />'.
                '</span>'
            ))
            ->darkModeBrandLogo(fn (): HtmlString => new HtmlString(
                '<span class="neo-brand-logo">'.
                '<img class="neo-brand-logo__horizontal" src="/branding/neopaylater.png" alt="Neo Paylater" />'.
                '<img class="neo-brand-logo__square" src="/branding/neopaylater.png" alt="Neo Paylater" />'.
                '</span>'
            ))
            ->brandLogoHeight('4rem')
            ->homeUrl(fn (): string => Overview::getUrl(panel: 'admin'))
            ->colors([
                'primary' => Color::Red,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                RedirectToLogin::class,
            ]);
    }
}
