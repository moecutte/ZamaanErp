<?php

namespace App\Filament\Resources\ProductResource\RelationManagers;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Set;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class FormsRelationManager extends RelationManager
{
    protected static string $relationship = 'forms';
    protected static ?string $title = 'Sell Forms';
    protected static ?string $recordTitleAttribute = 'name';

    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')
                ->required()
                ->maxLength(100)
                ->live(onBlur: true)
                ->afterStateUpdated(function (?string $state, Set $set, ?Model $record): void {
                    if ($record?->is_base) {
                        return;
                    }

                    if (filled($state)) {
                        $set('code', Str::slug($state));
                    }
                })
                ->helperText('e.g. Steak, Fillet, Cubes'),

            TextInput::make('code')
                ->required()
                ->maxLength(50)
                ->helperText('Unique per product (auto-filled from name).')
                ->disabled(fn (?Model $record) => $record?->is_base === true)
                ->dehydrated()
                ->rule(function (?Model $record) {
                    return \Illuminate\Validation\Rule::unique('product_forms', 'code')
                        ->where('product_id', $this->getOwnerRecord()->getKey())
                        ->ignore($record?->getKey());
                }),

            TextInput::make('sort_order')
                ->numeric()
                ->default(fn () => (int) $this->getOwnerRecord()->forms()->max('sort_order') + 1)
                ->required()
                ->minValue(0),

            Toggle::make('is_active')
                ->default(true)
                ->disabled(fn (?Model $record) => $record?->is_base === true)
                ->dehydrated(),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('code')->badge(),
                IconColumn::make('is_base')->boolean()->label('Base'),
                IconColumn::make('is_active')->boolean()->label('Active'),
                TextColumn::make('sort_order')->sortable(),
            ])
            ->defaultSort('sort_order')
            ->headerActions([
                CreateAction::make()
                    ->label('Add form')
                    ->modalHeading('Add sell form')
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['is_base'] = false;
                        $data['is_active'] = $data['is_active'] ?? true;
                        $data['code'] = Str::slug(strtolower(trim((string) ($data['code'] ?? $data['name'] ?? ''))));

                        return $data;
                    }),
            ])
            ->actions([
                EditAction::make()
                    ->mutateFormDataUsing(function (array $data, Model $record): array {
                        if ($record->is_base) {
                            $data['is_base'] = true;
                            $data['is_active'] = true;
                            $data['code'] = $record->code;
                        } else {
                            $data['is_base'] = false;
                            $data['code'] = Str::slug(strtolower(trim((string) ($data['code'] ?? $data['name'] ?? ''))));
                        }

                        return $data;
                    }),
                DeleteAction::make()
                    ->visible(fn (Model $record) => ! $record->is_base),
            ]);
    }
}
