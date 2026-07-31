<?php

namespace App\Filament\Resources;

use App\Enums\CustomerType;
use App\Filament\Concerns\HasRoleAccess;
use App\Filament\Resources\CustomerResource\Pages;
use App\Filament\Resources\CustomerResource\RelationManagers\PriceOverridesRelationManager;
use App\Models\Customer;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CustomerResource extends Resource
{
    use HasRoleAccess;

    protected static ?string $model = Customer::class;
    protected static ?string $navigationIcon = 'heroicon-o-users';
    protected static ?string $navigationGroup = 'Customers & Pricing';
    protected static ?int $navigationSort = 2;

    public static function allowedRoles(): array
    {
        return ['admin', 'sales_staff'];
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')
                ->required()
                ->maxLength(255)
                ->columnSpan(2),

            Select::make('type')
                ->options(collect(CustomerType::cases())->mapWithKeys(
                    fn (CustomerType $t) => [$t->value => $t->label()]
                ))
                ->required()
                ->live(),

            Select::make('pricing_tier_id')
                ->label('Pricing Tier')
                ->relationship(
                    'pricingTier',
                    'name',
                    fn ($query, Get $get) => $query->when(
                        $get('type'),
                        fn ($q, $type) => $q->where('customer_type', $type)
                    )
                )
                ->searchable()
                ->preload()
                ->nullable(),

            TextInput::make('contact_phone')
                ->tel()
                ->maxLength(50),

            TextInput::make('contact_email')
                ->email()
                ->maxLength(255),

            TextInput::make('credit_limit')
                ->numeric()
                ->prefix('$')
                ->minValue(0)
                ->nullable()
                ->visible(fn (Get $get) => in_array($get('type'), [
                    CustomerType::Restaurant->value,
                    CustomerType::Retailer->value,
                ], true)),

            TextInput::make('payment_terms_days')
                ->numeric()
                ->default(0)
                ->minValue(0)
                ->suffix('days')
                ->visible(fn (Get $get) => in_array($get('type'), [
                    CustomerType::Restaurant->value,
                    CustomerType::Retailer->value,
                ], true)),

            Textarea::make('address')
                ->rows(2)
                ->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (CustomerType $state) => $state->label())
                    ->colors([
                        'info'    => CustomerType::Household->value,
                        'warning' => CustomerType::Restaurant->value,
                        'success' => CustomerType::Retailer->value,
                    ]),
                TextColumn::make('pricingTier.name')
                    ->label('Pricing Tier')
                    ->placeholder('—'),
                TextColumn::make('contact_phone')->label('Phone')->toggleable(),
                TextColumn::make('credit_limit')
                    ->money('USD')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('payment_terms_days')
                    ->label('Terms (days)')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options(collect(CustomerType::cases())->mapWithKeys(
                        fn (CustomerType $t) => [$t->value => $t->label()]
                    )),
            ])
            ->actions([EditAction::make()])
            ->bulkActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function getRelationManagers(): array
    {
        return [
            PriceOverridesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListCustomers::route('/'),
            'create' => Pages\CreateCustomer::route('/create'),
            'edit'   => Pages\EditCustomer::route('/{record}/edit'),
        ];
    }
}
