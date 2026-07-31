<?php

namespace App\Filament\Pages;

use App\Enums\WastageReason;
use App\Filament\Concerns\HasRoleAccess;
use App\Models\Batch;
use App\Services\WastageService;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class RecordWastage extends Page implements HasForms
{
    use HasRoleAccess;
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-trash';
    protected static ?string $navigationLabel = 'Record Wastage';
    protected static ?string $navigationGroup = 'Inventory';
    protected static ?string $title = 'Record Wastage / Spoilage';
    protected static ?int $navigationSort = 10;
    protected static string $view = 'filament.pages.record-wastage';
    protected static ?string $slug = 'inventory/wastage';

    public static function allowedRoles(): array
    {
        return ['admin', 'warehouse_staff'];
    }

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('batch_id')
                    ->label('Batch')
                    ->options(
                        Batch::query()
                            ->with('product')
                            ->where('quantity_available', '>', 0)
                            ->orderBy('expiry_date')
                            ->get()
                            ->mapWithKeys(fn (Batch $b) => [
                                $b->id => "{$b->batch_code} — {$b->product->name} "
                                    . "(avail: {$b->quantity_available}, exp: {$b->expiry_date->toDateString()})",
                            ])
                    )
                    ->searchable()
                    ->required()
                    ->live(),

                TextInput::make('quantity')
                    ->numeric()
                    ->required()
                    ->minValue(0.001)
                    ->helperText('Must not exceed the selected batch available quantity.'),

                Select::make('reason')
                    ->options(collect(WastageReason::cases())->mapWithKeys(
                        fn (WastageReason $r) => [$r->value => $r->label()]
                    ))
                    ->required(),

                Textarea::make('notes')
                    ->rows(2)
                    ->nullable(),
            ])
            ->statePath('data');
    }

    public function submit(): void
    {
        $data = $this->form->getState();

        try {
            $batch = Batch::findOrFail($data['batch_id']);

            app(WastageService::class)->record(
                batch: $batch,
                quantity: (float) $data['quantity'],
                reason: WastageReason::from($data['reason']),
                notes: $data['notes'] ?? null,
            );

            Notification::make()
                ->title('Wastage recorded')
                ->body("Removed {$data['quantity']} from batch {$batch->batch_code}.")
                ->success()
                ->send();

            $this->form->fill([]);
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Wastage failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }
}
