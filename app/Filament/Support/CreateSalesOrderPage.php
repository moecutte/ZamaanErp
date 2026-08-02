<?php

namespace App\Filament\Support;

use App\Enums\CustomerType;
use App\Enums\SalesChannel;
use App\Enums\SalesOrderStatus;
use App\Filament\Concerns\HasRoleAccess;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductForm;
use App\Models\SalesOrder;
use App\Services\ConfirmSalesOrderService;
use App\Services\PricingResolutionService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

/**
 * Shared draft order form for credit channels (restaurant + wholesale).
 */
abstract class CreateSalesOrderPage extends Page implements HasForms
{
    use HasRoleAccess;
    use InteractsWithForms;

    protected static string $view = 'filament.pages.create-sales-order';
    protected static ?string $navigationGroup = 'Sales';

    public static function allowedRoles(): array
    {
        return ['admin', 'sales_staff'];
    }

    /** @return list<CustomerType> */
    abstract protected function customerTypes(): array;

    abstract protected function autoConfirm(): bool;

    abstract protected function allowsCredit(): bool;

    protected function resolveChannel(Customer $customer): SalesChannel
    {
        return SalesChannel::SalesOrder;
    }

    public function autoConfirmLabel(): string
    {
        return $this->autoConfirm() ? 'Complete Sale (FEFO + Deduct Stock)' : 'Save as Draft';
    }

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'order_date'        => now()->toDateString(),
            'delivery_required' => false,
            'lines'             => [['quantity' => 1, 'unit_price' => null, 'subtotal' => null]],
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema($this->getFormSchema())
            ->statePath('data');
    }

    protected function getFormSchema(): array
    {
        $types = $this->customerTypes();

        return [
            Section::make('Order Details')->schema([
                Select::make('customer_id')
                    ->label('Customer')
                    ->options(
                        Customer::query()
                            ->whereIn('type', $types)
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn (Customer $c) => [
                                $c->id => $c->name . ' (' . $c->type->label() . ')',
                            ])
                    )
                    ->searchable()
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn () => $this->repriceAllLines()),

                DatePicker::make('order_date')->required(),

                ...($this->allowsCredit() ? [
                    Toggle::make('delivery_required')->live(),
                    DatePicker::make('delivery_date')
                        ->visible(fn (Get $get) => (bool) $get('delivery_required')),
                    Placeholder::make('credit_info')
                        ->label('Credit Terms')
                        ->content(function (Get $get) {
                            $customer = Customer::find($get('customer_id'));
                            if (! $customer) {
                                return 'Select a customer to see credit terms.';
                            }

                            $limit = $customer->credit_limit !== null
                                ? \App\Support\Money::format($customer->credit_limit)
                                : 'No limit set';
                            $days = $customer->payment_terms_days;

                            return new HtmlString(
                                '<strong>Type:</strong> ' . $customer->type->label() . '<br>'
                                . "<strong>Credit limit:</strong> {$limit}<br>"
                                . "<strong>Payment terms:</strong> {$days} day(s)"
                            );
                        }),
                ] : []),
            ])->columns(2),

            Section::make('Line Items')->schema([
                Repeater::make('lines')
                    ->schema([
                        Select::make('product_id')
                            ->label('Product')
                            ->options(Product::query()->orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Get $get, Set $set) {
                                $set('product_form_id', null);
                                $product = Product::find($get('product_id'));
                                if ($product) {
                                    $set('product_form_id', $product->baseForm()?->id);
                                }
                                $this->repriceLine($get, $set);
                            })
                            ->columnSpan(2),

                        Select::make('product_form_id')
                            ->label('Form')
                            ->options(function (Get $get) {
                                $productId = $get('product_id');
                                if (! $productId) {
                                    return [];
                                }

                                return ProductForm::query()
                                    ->where('product_id', $productId)
                                    ->where('is_active', true)
                                    ->orderBy('sort_order')
                                    ->pluck('name', 'id');
                            })
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Get $get, Set $set) => $this->repriceLine($get, $set)),

                        TextInput::make('quantity')
                            ->numeric()
                            ->required()
                            ->minValue(0.001)
                            ->default(1)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Get $get, Set $set) => $this->repriceLine($get, $set)),

                        TextInput::make('unit_price')
                            ->numeric()
                            ->suffix(' ' . \App\Support\Money::label())
                            ->required()
                            ->minValue(0)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Get $get, Set $set) => $this->updateSubtotal($get, $set)),

                        TextInput::make('subtotal')
                            ->numeric()
                            ->suffix(' ' . \App\Support\Money::label())
                            ->readOnly(),
                    ])
                    ->columns(2)
                    ->minItems(1)
                    ->addActionLabel('Add Product')
                    ->defaultItems(1),
            ]),
        ];
    }

    protected function repriceLine(Get $get, Set $set): void
    {
        $customerId = $this->data['customer_id'] ?? null;
        $productId = $get('product_id');
        $formId = $get('product_form_id');
        $qty = (float) ($get('quantity') ?? 0);

        if (! $customerId || ! $productId) {
            return;
        }

        $customer = Customer::find($customerId);
        $product = Product::find($productId);

        if (! $customer || ! $product) {
            return;
        }

        $price = app(PricingResolutionService::class)->resolve(
            $customer,
            $product,
            $qty,
            $formId ? (int) $formId : null,
        );

        if ($price !== null) {
            $set('unit_price', $price);
            $set('subtotal', round($price * $qty, 2));
        } else {
            $this->updateSubtotal($get, $set);
        }
    }

    protected function updateSubtotal(Get $get, Set $set): void
    {
        $qty = (float) ($get('quantity') ?? 0);
        $price = (float) ($get('unit_price') ?? 0);
        $set('subtotal', round($qty * $price, 2));
    }

    protected function repriceAllLines(): void
    {
        $customerId = $this->data['customer_id'] ?? null;
        if (! $customerId || empty($this->data['lines'])) {
            return;
        }

        $customer = Customer::find($customerId);
        if (! $customer) {
            return;
        }

        $pricing = app(PricingResolutionService::class);

        foreach ($this->data['lines'] as $i => $line) {
            $productId = $line['product_id'] ?? null;
            $formId = $line['product_form_id'] ?? null;
            $qty = (float) ($line['quantity'] ?? 0);
            if (! $productId || $qty <= 0) {
                continue;
            }

            $product = Product::find($productId);
            if (! $product) {
                continue;
            }

            $price = $pricing->resolve(
                $customer,
                $product,
                $qty,
                $formId ? (int) $formId : null,
            );
            if ($price !== null) {
                $this->data['lines'][$i]['unit_price'] = $price;
                $this->data['lines'][$i]['subtotal'] = round($price * $qty, 2);
            }
        }
    }

    public function create(): void
    {
        $data = $this->form->getState();

        try {
            $order = DB::transaction(function () use ($data) {
                $customer = Customer::findOrFail($data['customer_id']);

                $order = SalesOrder::create([
                    'customer_id'       => $customer->id,
                    'channel'           => $this->resolveChannel($customer),
                    'order_date'        => $data['order_date'],
                    'status'            => SalesOrderStatus::Draft,
                    'delivery_required' => $data['delivery_required'] ?? false,
                    'delivery_date'     => $data['delivery_date'] ?? null,
                    'created_by'        => Auth::id(),
                ]);

                foreach ($data['lines'] as $line) {
                    $qty = (float) $line['quantity'];
                    $price = (float) $line['unit_price'];

                    $order->lines()->create([
                        'product_id' => $line['product_id'],
                        'product_form_id' => $line['product_form_id'],
                        'batch_id'   => null,
                        'quantity'   => $qty,
                        'unit_price' => $price,
                        'subtotal'   => round($qty * $price, 2),
                    ]);
                }

                if ($this->autoConfirm()) {
                    $order = app(ConfirmSalesOrderService::class)->confirm($order);
                }

                return $order;
            });

            Notification::make()
                ->title($this->autoConfirm() ? 'Sale completed' : 'Order saved as draft')
                ->body($this->autoConfirm()
                    ? 'Stock deducted via FEFO. Order #' . $order->id
                    : 'Order #' . $order->id . ' — confirm when ready to allocate stock.')
                ->success()
                ->send();

            $this->redirect(route('filament.admin.resources.sales-orders.view', ['record' => $order]));
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Could not create order')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }
}
