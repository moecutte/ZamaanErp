<?php

namespace App\Filament\Pages;

use App\Enums\StockMovementType;
use App\Enums\WastageReason;
use App\Filament\Concerns\HasRoleAccess;
use App\Models\Batch;
use App\Models\ProductProcessing;
use App\Models\StockMovement;
use App\Services\WastageService;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class RecordWastage extends Page implements HasForms, HasTable
{
    use HasRoleAccess;
    use InteractsWithForms;
    use InteractsWithTable;

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

    public string $activeTab = 'record';

    public function mount(): void
    {
        $this->form->fill([]);
    }

    public function setActiveTab(string $tab): void
    {
        $this->activeTab = in_array($tab, ['record', 'history'], true) ? $tab : 'record';
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('batch_id')
                    ->label('Batch')
                    ->options(
                        Batch::query()
                            ->with(['product', 'productForm'])
                            ->where('quantity_available', '>', 0)
                            ->orderBy('expiry_date')
                            ->get()
                            ->mapWithKeys(fn (Batch $b) => [
                                $b->id => "{$b->batch_code} — {$b->product->name}"
                                    . ($b->productForm ? " ({$b->productForm->name})" : '')
                                    . " (avail: {$b->quantity_available}, exp: {$b->expiry_date->toDateString()})",
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
                    ->options(
                        collect(WastageReason::cases())
                            ->reject(fn (WastageReason $r) => $r === WastageReason::Processing)
                            ->mapWithKeys(fn (WastageReason $r) => [$r->value => $r->label()])
                    )
                    ->required()
                    ->helperText('Processing waste is recorded automatically on Process Form.'),

                Textarea::make('notes')
                    ->rows(2)
                    ->nullable(),
            ])
            ->statePath('data');
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Waste history')
            ->description('Processing waste and recorded wastage / spoilage.')
            ->query(
                StockMovement::query()
                    ->where('type', StockMovementType::WastageOut)
                    ->with(['batch.product', 'batch.productForm', 'creator'])
            )
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('source')
                    ->label('Source')
                    ->badge()
                    ->state(fn (StockMovement $record) => $this->isProcessingWaste($record) ? 'Processing' : 'Spoilage')
                    ->color(fn (string $state) => $state === 'Processing' ? 'warning' : 'danger'),

                TextColumn::make('batch.product.name')
                    ->label('Product')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('batch.productForm.name')
                    ->label('Form')
                    ->badge()
                    ->placeholder('—'),

                TextColumn::make('batch.batch_code')
                    ->label('Batch')
                    ->searchable()
                    ->copyable(),

                TextColumn::make('quantity')
                    ->numeric(decimalPlaces: 3)
                    ->sortable(),

                TextColumn::make('reason')
                    ->label('Reason / notes')
                    ->wrap()
                    ->limit(60)
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('creator.name')
                    ->label('By')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('source')
                    ->label('Source')
                    ->options([
                        'processing' => 'Processing',
                        'spoilage' => 'Spoilage',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'processing' => $query->where(function (Builder $q) {
                                $q->where('reason', 'like', 'Processing%')
                                    ->orWhere('reference_type', ProductProcessing::class);
                            }),
                            'spoilage' => $query->where(function (Builder $q) {
                                $q->where(function (Builder $inner) {
                                    $inner->whereNull('reason')
                                        ->orWhere('reason', 'not like', 'Processing%');
                                })->where(function (Builder $inner) {
                                    $inner->whereNull('reference_type')
                                        ->orWhere('reference_type', '!=', ProductProcessing::class);
                                });
                            }),
                            default => $query,
                        };
                    }),
            ])
            ->paginated([10, 25, 50]);
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
            $this->resetTable();
            $this->activeTab = 'history';
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Wastage failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    private function isProcessingWaste(StockMovement $record): bool
    {
        if ($record->reference_type === ProductProcessing::class) {
            return true;
        }

        return str_starts_with((string) $record->reason, WastageReason::Processing->label());
    }
}
