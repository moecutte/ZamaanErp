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

                        /* Shared sidebar chrome */
                        .fi-sidebar,
                        .fi-sidebar-nav {
                            overflow-x: hidden !important;
                            scrollbar-width: thin;
                        }
                        .fi-sidebar-nav {
                            overflow-y: auto !important;
                            padding-block: 0.75rem !important;
                        }
                        .fi-sidebar::-webkit-scrollbar,
                        .fi-sidebar-nav::-webkit-scrollbar {
                            width: 8px;
                            height: 8px;
                        }
                        .fi-sidebar::-webkit-scrollbar-corner,
                        .fi-sidebar-nav::-webkit-scrollbar-corner {
                            background: transparent;
                        }
                        .fi-sidebar-group-label {
                            letter-spacing: 0.06em;
                            text-transform: uppercase;
                            font-size: 0.68rem !important;
                            font-weight: 700 !important;
                        }
                        .fi-sidebar-item-button {
                            border-radius: 0.65rem !important;
                            transition: background-color 0.15s ease, color 0.15s ease;
                        }
                        .fi-sidebar-group-items {
                            gap: 0.2rem !important;
                        }

                        /* Light mode — branded teal sidebar */
                        html:not(.dark) .fi-sidebar {
                            --sidebar-fg: rgba(255, 255, 255, 0.82);
                            --sidebar-fg-muted: rgba(255, 255, 255, 0.55);
                            --sidebar-fg-strong: #ffffff;
                            --sidebar-hover: rgba(255, 255, 255, 0.1);
                            --sidebar-active: rgba(255, 255, 255, 0.16);
                            background:
                                radial-gradient(120% 80% at 0% 0%, rgba(255, 255, 255, 0.14), transparent 55%),
                                linear-gradient(180deg, rgb(var(--primary-700)) 0%, rgb(var(--primary-800)) 48%, rgb(var(--primary-900)) 100%) !important;
                            border-inline-end: 1px solid rgba(255, 255, 255, 0.08) !important;
                            box-shadow: inset -1px 0 0 rgba(0, 0, 0, 0.12);
                            scrollbar-color: rgba(255, 255, 255, 0.28) transparent;
                        }
                        html:not(.dark) .fi-sidebar-header {
                            background: transparent !important;
                            border-bottom: 1px solid rgba(255, 255, 255, 0.1) !important;
                            box-shadow: none !important;
                        }
                        html:not(.dark) .fi-sidebar .fi-logo {
                            filter: brightness(0) invert(1);
                            opacity: 0.95;
                        }
                        html:not(.dark) .fi-sidebar .fi-icon-btn,
                        html:not(.dark) .fi-sidebar-group-label,
                        html:not(.dark) .fi-sidebar-group-button,
                        html:not(.dark) .fi-sidebar-group-collapse-button,
                        html:not(.dark) .fi-sidebar-item-button,
                        html:not(.dark) .fi-sidebar-item-icon,
                        html:not(.dark) .fi-sidebar-item-label,
                        html:not(.dark) .fi-sidebar-group-icon {
                            color: var(--sidebar-fg) !important;
                        }
                        html:not(.dark) .fi-sidebar-group-label,
                        html:not(.dark) .fi-sidebar-group-button,
                        html:not(.dark) .fi-sidebar-group-collapse-button {
                            color: var(--sidebar-fg-muted) !important;
                        }
                        html:not(.dark) .fi-sidebar .fi-icon-btn:hover,
                        html:not(.dark) .fi-sidebar-group-button:hover,
                        html:not(.dark) .fi-sidebar-group-collapse-button:hover,
                        html:not(.dark) .fi-sidebar-item-button:hover {
                            background: var(--sidebar-hover) !important;
                            color: var(--sidebar-fg-strong) !important;
                        }
                        html:not(.dark) .fi-sidebar-item-button:hover .fi-sidebar-item-icon,
                        html:not(.dark) .fi-sidebar-item-button:hover .fi-sidebar-item-label {
                            color: var(--sidebar-fg-strong) !important;
                        }
                        html:not(.dark) .fi-sidebar-item-active > .fi-sidebar-item-button,
                        html:not(.dark) .fi-sidebar-item-active .fi-sidebar-item-button {
                            background: var(--sidebar-active) !important;
                            box-shadow: inset 3px 0 0 #fff;
                            color: var(--sidebar-fg-strong) !important;
                        }
                        html:not(.dark) .fi-sidebar-item-active .fi-sidebar-item-icon,
                        html:not(.dark) .fi-sidebar-item-active .fi-sidebar-item-label {
                            color: var(--sidebar-fg-strong) !important;
                            font-weight: 600;
                        }
                        html:not(.dark) .fi-sidebar .fi-badge {
                            background: rgba(255, 255, 255, 0.18) !important;
                            color: #fff !important;
                        }
                        html:not(.dark) .fi-sidebar::-webkit-scrollbar-track,
                        html:not(.dark) .fi-sidebar-nav::-webkit-scrollbar-track {
                            background: rgba(0, 0, 0, 0.12);
                            border-radius: 999px;
                            margin-block: 0.5rem;
                        }
                        html:not(.dark) .fi-sidebar::-webkit-scrollbar-thumb,
                        html:not(.dark) .fi-sidebar-nav::-webkit-scrollbar-thumb {
                            background: rgba(255, 255, 255, 0.28);
                            border-radius: 999px;
                            border: 2px solid transparent;
                            background-clip: padding-box;
                        }
                        html:not(.dark) .fi-sidebar::-webkit-scrollbar-thumb:hover,
                        html:not(.dark) .fi-sidebar-nav::-webkit-scrollbar-thumb:hover {
                            background: rgba(255, 255, 255, 0.45);
                            border: 2px solid transparent;
                            background-clip: padding-box;
                        }

                        /* Dark mode — full dark sidebar matching the panel */
                        .dark .fi-sidebar {
                            --sidebar-fg: rgb(var(--gray-300));
                            --sidebar-fg-muted: rgb(var(--gray-400));
                            --sidebar-fg-strong: rgb(var(--gray-100));
                            --sidebar-hover: rgba(255, 255, 255, 0.06);
                            --sidebar-active: rgba(var(--primary-500), 0.18);
                            background: rgb(var(--gray-950)) !important;
                            border-inline-end: 1px solid rgb(var(--gray-800)) !important;
                            box-shadow: none;
                            scrollbar-color: rgb(var(--gray-600)) transparent;
                        }
                        .dark .fi-sidebar-header {
                            background: rgb(var(--gray-950)) !important;
                            border-bottom: 1px solid rgb(var(--gray-800)) !important;
                            box-shadow: none !important;
                        }
                        .dark .fi-sidebar .fi-logo {
                            filter: none;
                            opacity: 1;
                        }
                        .dark .fi-sidebar .fi-icon-btn,
                        .dark .fi-sidebar-group-label,
                        .dark .fi-sidebar-group-button,
                        .dark .fi-sidebar-group-collapse-button,
                        .dark .fi-sidebar-item-button,
                        .dark .fi-sidebar-item-icon,
                        .dark .fi-sidebar-item-label,
                        .dark .fi-sidebar-group-icon {
                            color: var(--sidebar-fg) !important;
                        }
                        .dark .fi-sidebar-group-label,
                        .dark .fi-sidebar-group-button,
                        .dark .fi-sidebar-group-collapse-button {
                            color: var(--sidebar-fg-muted) !important;
                        }
                        .dark .fi-sidebar .fi-icon-btn:hover,
                        .dark .fi-sidebar-group-button:hover,
                        .dark .fi-sidebar-group-collapse-button:hover,
                        .dark .fi-sidebar-item-button:hover {
                            background: var(--sidebar-hover) !important;
                            color: var(--sidebar-fg-strong) !important;
                        }
                        .dark .fi-sidebar-item-button:hover .fi-sidebar-item-icon,
                        .dark .fi-sidebar-item-button:hover .fi-sidebar-item-label {
                            color: var(--sidebar-fg-strong) !important;
                        }
                        .dark .fi-sidebar-item-active > .fi-sidebar-item-button,
                        .dark .fi-sidebar-item-active .fi-sidebar-item-button {
                            background: var(--sidebar-active) !important;
                            box-shadow: inset 3px 0 0 rgb(var(--primary-400));
                            color: var(--sidebar-fg-strong) !important;
                        }
                        .dark .fi-sidebar-item-active .fi-sidebar-item-icon,
                        .dark .fi-sidebar-item-active .fi-sidebar-item-label {
                            color: rgb(var(--primary-300)) !important;
                            font-weight: 600;
                        }
                        .dark .fi-sidebar .fi-badge {
                            background: rgb(var(--gray-800)) !important;
                            color: rgb(var(--gray-200)) !important;
                        }
                        .dark .fi-sidebar::-webkit-scrollbar-track,
                        .dark .fi-sidebar-nav::-webkit-scrollbar-track {
                            background: rgb(var(--gray-900));
                            border-radius: 999px;
                            margin-block: 0.5rem;
                        }
                        .dark .fi-sidebar::-webkit-scrollbar-thumb,
                        .dark .fi-sidebar-nav::-webkit-scrollbar-thumb {
                            background: rgb(var(--gray-600));
                            border-radius: 999px;
                            border: 2px solid transparent;
                            background-clip: padding-box;
                        }
                        .dark .fi-sidebar::-webkit-scrollbar-thumb:hover,
                        .dark .fi-sidebar-nav::-webkit-scrollbar-thumb:hover {
                            background: rgb(var(--gray-500));
                            border: 2px solid transparent;
                            background-clip: padding-box;
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
