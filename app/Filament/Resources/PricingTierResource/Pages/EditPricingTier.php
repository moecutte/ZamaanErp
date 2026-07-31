<?php

namespace App\Filament\Resources\PricingTierResource\Pages;

use App\Filament\Resources\PricingTierResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPricingTier extends EditRecord
{
    protected static string $resource = PricingTierResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
