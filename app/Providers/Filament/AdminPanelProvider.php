<?php

namespace App\Providers\Filament;

use App\Filament\Auth\Login;
use App\Http\Middleware\RedirectToLogin;
use App\Filament\Pages\Overview;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
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
            ->favicon(asset('favicon.ico'))
            ->brandLogo(fn (): HtmlString => new HtmlString(
                '<span class="neo-brand-logo">' .
                '<img class="neo-brand-logo__horizontal" src="' . asset('branding/neopaylater.png') . '" alt="Neo Paylater" />' .
                '<img class="neo-brand-logo__square" src="' . asset('branding/neopaylatersquare.png') . '" alt="Neo Paylater" />' .
                '</span>'
            ))
            ->darkModeBrandLogo(fn (): HtmlString => new HtmlString(
                '<span class="neo-brand-logo">' .
                '<img class="neo-brand-logo__horizontal" src="' . asset('branding/neopaylater.png') . '" alt="Neo Paylater" />' .
                '<img class="neo-brand-logo__square" src="' . asset('branding/neopaylatersquare.png') . '" alt="Neo Paylater" />' .
                '</span>'
            ))
            ->brandLogoHeight('4rem')
            ->renderHook(
                PanelsRenderHook::AUTH_LOGIN_FORM_AFTER,
                fn (): HtmlString => $this->renderLoginCrabs(),
            )
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

    private function renderLoginCrabs(): HtmlString
    {
        $crabs = '';

        for ($index = 0; $index < 7; $index++) {
            $isReverse = (bool) random_int(0, 1);

            $crabs .= sprintf(
                '<span class="neo-login-crab%s" style="--crab-left:%d%%;--crab-bottom:%.1frem;--crab-size:%.2frem;--crab-duration:%ds;--crab-delay:%ds;--crab-travel:%dvw;--crab-opacity:%.2f;">🦀</span>',
                $isReverse ? ' neo-login-crab--reverse' : '',
                random_int(2, 88),
                random_int(0, 18) / 10,
                random_int(18, 28) / 10,
                random_int(14, 24),
                random_int(-20, 0),
                random_int(16, 34),
                random_int(65, 100) / 100,
            );
        }

        return new HtmlString('<div class="neo-login-crabs" aria-hidden="true">' . $crabs . '</div>');
    }
}
