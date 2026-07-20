<?php

namespace App\Providers\Filament;

use DutchCodingCompany\FilamentDeveloperLogins\FilamentDeveloperLoginsPlugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\MenuItem;
use Filament\Navigation\NavigationGroup;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Joaopaulolndev\FilamentEditProfile\FilamentEditProfilePlugin;
use Joaopaulolndev\FilamentEditProfile\Pages\EditProfilePage;
use pxlrbt\FilamentEnvironmentIndicator\EnvironmentIndicatorPlugin;
use SolutionForest\FilamentTranslateField\FilamentTranslateFieldPlugin;
use Stephenjude\FilamentDebugger\DebuggerPlugin;

class BackofficePanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $panel = $panel
            ->default()
            ->id('backoffice')
            ->path('backoffice')
            ->spa()
            ->sidebarCollapsibleOnDesktop()
            ->login(\App\Filament\Pages\Auth\Login::class)
            ->databaseNotifications()
            ->plugins([
                EnvironmentIndicatorPlugin::make()
                    ->color(fn () => match (app()->environment()) {
                        'production' => Color::Green,
                        'staging' => Color::Orange,
                        default => Color::Rose,
                    })
                    ->showBadge(function (){
                        if (app()->hasDebugModeEnabled()) return true;
                        if (auth()->user()->hasRole('developer')) return true;
                        return false;
                    })
                    ->showBorder(false)
                    ->visible(true),
                FilamentEditProfilePlugin::make()
                    ->shouldRegisterNavigation(false)
                    ->shouldShowAvatarForm()

                    ->shouldShowDeleteAccountForm(false),
                FilamentTranslateFieldPlugin::make()
                    ->defaultLocales(['en', 'pt-pt']),
                DebuggerPlugin::make()
                    ->authorize(condition: fn() => auth()->user()->hasRole('developer'))
        ])
            ->userMenuItems([
                'profile' => MenuItem::make()
                    ->label(fn () => auth()->user()->name)
                    ->url(fn (): string => EditProfilePage::getUrl())
                    ->icon('heroicon-m-user-circle'),
            ])
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                Widgets\AccountWidget::class,
                //Widgets\FilamentInfoWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                \App\Http\Middleware\SecurityHeaders::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->navigationGroups([
                NavigationGroup::make()
                    ->label('User Management'),
                NavigationGroup::make()
                    ->label('General Settings')
                    ->collapsed(),
            ])
            ->favicon(asset('favicon-32x32.png'))
            ->registration(null)
            ->brandLogo(fn () => view('filament.admin.logo'))
            ->viteTheme('resources/css/filament/backoffice/theme.css');

        // developer-logins é dependência só-dev (require-dev). Em produção corre-se
        // `composer install --no-dev` e a classe não existe → registar apenas fora de
        // produção E só se a classe estiver instalada, senão o boot do painel rebentava.
        if (! app()->environment('production') && class_exists(FilamentDeveloperLoginsPlugin::class)) {
            $panel->plugin(
                FilamentDeveloperLoginsPlugin::make()
                    ->switchable(false)
                    ->users(['Admin' => 'admin@example.com'])
            );
        }

        return $panel;
    }
}
