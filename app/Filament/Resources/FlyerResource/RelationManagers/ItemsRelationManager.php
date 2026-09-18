<?php

declare(strict_types=1);

namespace App\Filament\Resources\FlyerResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $recordTitleAttribute = 'product_name';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('product_name')
                    ->label('Product Name')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (string $operation, $state, Forms\Set $set, Forms\Get $get): void {
                        $currentSlug = $get('slug');
                        if (blank($currentSlug) && filled($state)) {
                            $set('slug', Str::slug((string) $state) . '-' . substr(Str::ulid()->toString(), -4));
                        }
                    })
                    ->columnSpanFull(),

                Forms\Components\TextInput::make('slug')
                    ->label('Slug')
                    ->maxLength(255)
                    ->placeholder('Auto-generated if empty')
                    ->helperText('Auto-generated from product name if left empty.'),

                Forms\Components\TextInput::make('sale_price')
                    ->label('Sale Price (EGP)')
                    ->numeric()
                    ->prefix('EGP')
                    ->step(0.01)
                    ->minValue(0)
                    ->required(),

                Forms\Components\TextInput::make('old_price')
                    ->label('Old Price (EGP)')
                    ->numeric()
                    ->prefix('EGP')
                    ->step(0.01)
                    ->minValue(0)
                    ->nullable()
                    ->helperText('Crossed-out price, null if single price.'),

                Forms\Components\TextInput::make('unit')
                    ->label('Unit')
                    ->placeholder('كجم / لتر / قطعة')
                    ->maxLength(50)
                    ->nullable(),

                Forms\Components\Select::make('brand_id')
                    ->label('Brand')
                    ->relationship('brand', 'name')
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->placeholder('— No brand —'),

                Forms\Components\Select::make('flyer_page_id')
                    ->label('Page')
                    ->options(fn (): array => $this->getOwnerRecord()->pages()->orderBy('page_number')->pluck('page_number', 'id')->mapWithKeys(static fn ($num, $id): array => [$id => 'Page ' . $num])->all())
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->helperText('Link item to specific page if known.'),

                Forms\Components\TextInput::make('bundle_condition')
                    ->label('Bundle Condition')
                    ->placeholder('حد أقصى 2 قطعة')
                    ->maxLength(255)
                    ->nullable()
                    ->columnSpanFull(),

                Forms\Components\KeyValue::make('extra_attributes')
                    ->label('Extra Attributes')
                    ->keyLabel('Key')
                    ->valueLabel('Value')
                    ->columnSpanFull(),

                Forms\Components\Toggle::make('is_featured')
                    ->label('Featured')
                    ->default(false),

                Forms\Components\Placeholder::make('scraped_time')
                    ->label('تاريخ رصد السعر')
                    ->content(fn ($record): string => $record?->created_at
                        ? $record->created_at->timezone('Africa/Cairo')->format('Y/m/d h:i A') . ' (' . $record->created_at->diffForHumans() . ')'
                        : 'جديد'),
            ])
            ->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('product_name')
            ->columns([
                Tables\Columns\TextColumn::make('product_name')
                    ->label('Product')
                    ->searchable()
                    ->sortable()
                    ->limit(40)
                    ->weight('medium'),

                Tables\Columns\TextColumn::make('sale_price')
                    ->label('Sale (EGP)')
                    ->numeric(2)
                    ->sortable(),

                Tables\Columns\TextColumn::make('old_price')
                    ->label('Old (EGP)')
                    ->numeric(2)
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('discount_percent')
                    ->label('Discount %')
                    ->numeric(2)
                    ->sortable()
                    ->badge()
                    ->color('success'),

                Tables\Columns\TextColumn::make('brand.name')
                    ->label('Brand')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('flyerPage.page_number')
                    ->label('Page')
                    ->formatStateUsing(fn ($state): string => $state !== null ? 'Page ' . $state : '—')
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_featured')
                    ->label('Featured')
                    ->boolean()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_featured')
                    ->label('Featured only')
                    ->placeholder('All items')
                    ->trueLabel('Featured')
                    ->falseLabel('Not featured'),

                Tables\Filters\Filter::make('has_discount')
                    ->label('Has discount')
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('discount_percent')->where('discount_percent', '>', 0)),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->modalWidth('3xl'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make()
                    ->modalWidth('3xl'),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('id')
            ->defaultPaginationPageOption(10)
            ->paginated([10, 25, 50]);
    }
}
