<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\HasRoleAccess;
use Filament\Pages\Page;

/**
 * Nav entry that opens the standalone Odoo-style POS (no Filament chrome).
 */
class PosTerminal extends Page
{
    use HasRoleAccess;

    protected static ?string $navigationIcon = 'heroicon-o-calculator';
    protected static ?string $navigationLabel = 'POS';
    protected static ?string $title = 'POS';
    protected static ?string $navigationGroup = 'Sales';
    protected static ?int $navigationSort = 1;
    protected static ?string $slug = 'sales/pos';
    protected static string $view = 'filament.pages.pos-redirect';

    public static function allowedRoles(): array
    {
        return ['admin', 'sales_staff'];
    }

    public function mount(): void
    {
        $this->redirect(route('pos'), navigate: false);
    }
}
