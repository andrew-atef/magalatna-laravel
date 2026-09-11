<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\RetailerResource\Pages;
use App\Models\Retailer;
use App\Services\ImageOptimizerService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

final class RetailerResource extends Resource
{
    protected static ?string $model = Retailer::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Retailer Details')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Name')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (string $operation, $state, Forms\Set $set): void {
                                if ($operation === 'create') {
                                    $set('slug', Str::slug((string) $state));
                                }
                            }),

                        Forms\Components\TextInput::make('slug')
                            ->label('Slug')
                            ->required()
                            ->maxLength(255)
                            ->unique(Retailer::class, 'slug', ignoreRecord: true)
                            ->helperText('Unique URL slug, auto-generated from name.'),

                        Forms\Components\Select::make('parent_id')
                            ->label('Parent Company (Branch Link)')
                            ->relationship('parent', 'name')
                            ->searchable()
                            ->preload()
                            ->nullable()
                            ->placeholder('— Top-level retailer —')
                            ->helperText('Search other retailers to link branch to parent company.'),

                        Forms\Components\FileUpload::make('logo_path')
                            ->label('Logo')
                            ->disk(self::storageDisk())
                            ->directory('retailer-logos')
                            ->visibility('public')
                            ->image()
                            ->imageEditor()
                            ->maxSize(2048)
                            ->helperText(fn (): string => self::isR2Configured() ? 'Stored on R2 disk in retailer-logos/ (auto-converted to WebP 1200px, quality 80).' : 'R2 not configured — stored locally in storage/app/public/retailer-logos (auto WebP).')
                            ->saveUploadedFileUsing(function (TemporaryUploadedFile $file): string {
                                $disk = self::storageDisk();
                                /** @var ImageOptimizerService $optimizer */
                                $optimizer = app(ImageOptimizerService::class);

                                // TemporaryUploadedFile extends UploadedFile, process via service
                                return $optimizer->processUploadedFile($file, 'retailer-logos', $disk);
                            })
                            ->columnSpanFull(),

                        Forms\Components\TextInput::make('website_url')
                            ->label('Website URL')
                            ->url()
                            ->maxLength(255)
                            ->nullable()
                            ->placeholder('https://example.com'),

                        Forms\Components\TextInput::make('currency')
                            ->label('Currency')
                            ->default('EGP')
                            ->maxLength(10)
                            ->required(),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->inline(false)
                            ->helperText('Inactive retailers are hidden from storefront.'),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('logo_path')
                    ->label('Logo')
                    ->disk(self::storageDisk())
                    ->circular()
                    ->size(40)
                    ->defaultImageUrl(url('/images/placeholder-retailer.png')),

                Tables\Columns\TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),

                Tables\Columns\TextColumn::make('parent.name')
                    ->label('Branch Parent')
                    ->placeholder('—')
                    ->sortable()
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\TextColumn::make('flyers_count')
                    ->label('Flyers')
                    ->counts('flyers')
                    ->sortable()
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('slug')
                    ->label('Slug')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->copyMessage('تم نسخ الـ slug')
                    ->copyMessageDuration(1500)
                    ->badge()
                    ->color('gray')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active status')
                    ->placeholder('All retailers')
                    ->trueLabel('Only active')
                    ->falseLabel('Only inactive'),

                Tables\Filters\SelectFilter::make('parent_id')
                    ->label('Parent Company')
                    ->relationship('parent', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('name');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRetailers::route('/'),
            'create' => Pages\CreateRetailer::route('/create'),
            'edit' => Pages\EditRetailer::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['parent'])->withCount('flyers');
    }

    private static function isR2Configured(): bool
    {
        return filled(config('filesystems.disks.r2.bucket')) && filled(config('filesystems.disks.r2.endpoint'));
    }

    private static function storageDisk(): string
    {
        return self::isR2Configured() ? 'r2' : 'public';
    }
}
