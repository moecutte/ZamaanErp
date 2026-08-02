<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\HasRoleAccess;
use App\Models\Batch;
use App\Models\Product;
use App\Models\ProductForm;
use App\Services\ProcessProductFormService;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\HtmlString;

class ProcessProductForm extends Page implements HasForms
{
    use HasRoleAccess;
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-scissors';
    protected static ?string $navigationLabel = 'Process Form';
    protected static ?string $navigationGroup = 'Inventory';
    protected static ?string $title = 'Process Product Form';
    protected static ?int $navigationSort = 9;
    protected static string $view = 'filament.pages.process-product-form';
    protected static ?string $slug = 'inventory/process-form';

    public static function allowedRoles(): array
    {
        return ['admin', 'warehouse_staff'];
    }

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'outputs' => [['product_form_id' => null, 'quantity' => null]],
            'waste_quantity' => 0,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Source (weigh in)')->schema([
                    Select::make('product_id')
                        ->label('Product')
                        ->options(Product::query()->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (Set $set): void {
                            $set('source_batch_id', null);
                            $set('outputs', [['product_form_id' => null, 'quantity' => null]]);
                        }),

                    Select::make('source_batch_id')
                        ->label('Source batch (base form)')
                        ->options(function (Get $get) {
                            $productId = $get('product_id');
                            if (! $productId) {
                                return [];
                            }

                            $baseFormId = ProductForm::query()
                                ->where('product_id', $productId)
                                ->where('is_base', true)
                                ->value('id');

                            if (! $baseFormId) {
                                return [];
                            }

                            return Batch::query()
                                ->where('product_id', $productId)
                                ->where('product_form_id', $baseFormId)
                                ->where('quantity_available', '>', 0)
                                ->orderBy('expiry_date')
                                ->get()
                                ->mapWithKeys(fn (Batch $b) => [
                                    $b->id => "{$b->batch_code} — avail {$b->quantity_available} "
                                        . "(exp {$b->expiry_date->toDateString()})",
                                ]);
                        })
                        ->searchable()
                        ->required()
                        ->live(),

                    TextInput::make('source_quantity')
                        ->label('Source weight (used)')
                        ->numeric()
                        ->required()
                        ->minValue(0.001)
                        ->live(onBlur: true)
                        ->helperText('Weigh the amount taken from the source batch.'),

                    Placeholder::make('balance_hint')
                        ->label('Balance check')
                        ->content(function (Get $get) {
                            $source = (float) ($get('source_quantity') ?? 0);
                            $waste = (float) ($get('waste_quantity') ?? 0);
                            $processed = collect($get('outputs') ?? [])
                                ->sum(fn ($row) => (float) ($row['quantity'] ?? 0));
                            $accounted = round($processed + $waste, 3);
                            $delta = round($source - $accounted, 3);

                            return new HtmlString(
                                "Processed: <strong>{$processed}</strong> + Waste: <strong>{$waste}</strong> "
                                . "= <strong>{$accounted}</strong> vs Source <strong>{$source}</strong> "
                                . '(diff ' . $delta . ')'
                            );
                        }),
                ])->columns(2),

                Section::make('Processed outputs (weigh out)')->schema([
                    Placeholder::make('forms_hint')
                        ->label('')
                        ->content(function (Get $get) {
                            $productId = $get('product_id');
                            if (! $productId) {
                                return 'Select a product first.';
                            }

                            $count = ProductForm::query()
                                ->where('product_id', $productId)
                                ->where('is_base', false)
                                ->where('is_active', true)
                                ->count();

                            if ($count === 0) {
                                return new HtmlString(
                                    '<span class="text-warning-600">No sell forms yet for this product. '
                                    . 'Open the product → <strong>Sell Forms</strong> tab and add Steak, Fillet, etc.</span>'
                                );
                            }

                            return "{$count} form(s) available for output.";
                        }),

                    Repeater::make('outputs')
                        ->schema([
                            Select::make('product_form_id')
                                ->label('Form')
                                ->options(fn (Get $get) => $this->outputFormOptions($get))
                                ->searchable()
                                ->required()
                                ->live()
                                ->columnSpan(2),

                            TextInput::make('quantity')
                                ->label('Processed weight')
                                ->numeric()
                                ->required()
                                ->minValue(0.001)
                                ->live(onBlur: true),
                        ])
                        ->columns(3)
                        ->minItems(1)
                        ->addActionLabel('Add form output')
                        ->live(),
                ]),

                Section::make('Waste (weigh out)')->schema([
                    TextInput::make('waste_quantity')
                        ->label('Waste weight')
                        ->numeric()
                        ->required()
                        ->minValue(0)
                        ->default(0)
                        ->live(onBlur: true)
                        ->helperText('Weigh discarded material from this processing.'),

                    Textarea::make('notes')
                        ->rows(2)
                        ->nullable(),
                ])->columns(2),
            ])
            ->statePath('data');
    }

    /**
     * @return array<int|string, string>
     */
    private function outputFormOptions(Get $get): array
    {
        // From repeater item: climb to root form state for product_id
        $productId = $get('../../product_id')
            ?? $get('../product_id')
            ?? ($this->data['product_id'] ?? null);

        if (! $productId) {
            return [];
        }

        return ProductForm::query()
            ->where('product_id', $productId)
            ->where('is_base', false)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public function submit(): void
    {
        $data = $this->form->getState();

        try {
            $batch = Batch::findOrFail($data['source_batch_id']);

            $processing = app(ProcessProductFormService::class)->process(
                sourceBatch: $batch,
                sourceQuantity: (float) $data['source_quantity'],
                wasteQuantity: (float) ($data['waste_quantity'] ?? 0),
                outputs: $data['outputs'] ?? [],
                notes: $data['notes'] ?? null,
            );

            $outputSummary = $processing->outputs
                ->map(fn ($o) => $o->productForm->name . ': ' . $o->quantity)
                ->implode(', ');

            Notification::make()
                ->title('Processing recorded')
                ->body("Waste {$processing->waste_quantity}. Outputs — {$outputSummary}.")
                ->success()
                ->send();

            $this->form->fill([
                'product_id' => $data['product_id'] ?? null,
                'outputs' => [['product_form_id' => null, 'quantity' => null]],
                'waste_quantity' => 0,
            ]);
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Processing failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }
}
