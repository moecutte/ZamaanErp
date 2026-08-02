<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\DashboardReportWidget;
use App\Filament\Widgets\ExpiringBatchesWidget;
use App\Filament\Widgets\LowStockWidget;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\MaxWidth;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
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
            ->login()
            ->brandName('Zamaan Seafood')
            ->brandLogo(asset('images/zamaan-logo.png'))
            ->darkModeBrandLogo(asset('images/zamaan-logo-dark.png'))
            ->brandLogoHeight('3.75rem')
            ->favicon(asset('favicon.png'))
            ->renderHook(
                PanelsRenderHook::STYLES_AFTER,
                fn (): HtmlString => new HtmlString(<<<'CSS'
                    <style>
                        .fi-logo {
                            padding: 0.4rem 0.6rem !important;
                            box-sizing: content-box;
                            object-fit: contain;
                        }
                        .fi-sidebar-header,
                        .fi-topbar .fi-logo {
                            margin-inline: 0.15rem;
                        }
                        .fi-page-dashboard .fi-wi-widget {
                            height: 100%;
                        }
                        .fi-wi-table .fi-ta-content {
                            max-height: 13.5rem;
                            overflow: auto;
                        }
                        .fi-wi-table .fi-ta-header-toolbar {
                            margin-block: 0.15rem;
                        }
                    </style>
                    CSS)
            )
            ->colors([
                'primary' => Color::Teal,
                'danger' => Color::Rose,
                'warning' => Color::Amber,
                'success' => Color::Emerald,
                'info' => Color::Sky,
            ])
            ->font('Segoe UI')
            ->sidebarCollapsibleOnDesktop()
            ->sidebarWidth('15rem')
            ->collapsedSidebarWidth('4.25rem')
            ->maxContentWidth(MaxWidth::Full)
            ->navigationGroups([
                NavigationGroup::make('Sales')->collapsed(false),
                NavigationGroup::make('Inventory')->collapsed(false),
                NavigationGroup::make('Purchasing')->collapsed(false),
                NavigationGroup::make('Customers & Pricing')->collapsed(false),
                NavigationGroup::make('Finance')->collapsed(true),
                NavigationGroup::make('Reports')->collapsed(true),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                DashboardReportWidget::class,
                ExpiringBatchesWidget::class,
                LowStockWidget::class,
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
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
