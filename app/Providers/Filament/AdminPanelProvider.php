<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Today;
use App\Http\Middleware\EnsureUserIsNotBlocked;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('crm')
            ->path('')
            ->brandName('ADK CRM')
            ->login()
            ->profile(isSimple: false)
            // Zwei-Faktor-Anmeldung per Authenticator-App ist für alle Konten Pflicht.
            ->multiFactorAuthentication(
                AppAuthentication::make()
                    ->brandName('ADK CRM')
                    ->recoverable()
                    ->recoveryCodeCount(8),
                isRequired: true,
            )
            // Keine Schriften von fremden Servern: Inter liegt lokal unter public/fonts.
            ->font('Inter', url: fn () => asset('fonts/filament/filament/inter/index.css'), provider: LocalFontProvider::class)
            ->colors([
                'primary' => Color::Blue,
                'danger' => Color::Red,
            ])
            ->viteTheme('resources/css/filament/crm/theme.css')
            ->navigationGroups([
                NavigationGroup::make('Akquise'),
                NavigationGroup::make('Stammdaten'),
                NavigationGroup::make('Verwaltung'),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Today::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->databaseNotifications(false)
            ->unsavedChangesAlerts()
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
                // Filament-Anmeldeprüfung plus sofortige Abmeldung gesperrter Konten.
                EnsureUserIsNotBlocked::class,
            ]);
    }
}
