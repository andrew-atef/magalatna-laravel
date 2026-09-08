<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\FlyerStatus;
use App\Filament\Resources\FlyerResource\Pages;
use App\Jobs\PingIndexNowJob;
use App\Models\Flyer;
use App\Services\ImageOptimizerService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

final class FlyerResource extends Resource
{
    protected static ?string $model = Flyer::class;

    protected static ?string $navigationIcon = 'heroicon-o-photo';

    protected static ?string $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Header')
                    ->description('Core flyer metadata and publication window.')
                    ->schema([
                        Forms\Components\Select::make('retailer_id')
                            ->label('Retailer')
                            ->relationship('retailer', 'name')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->native(false),

                        Forms\Components\TextInput::make('title')
                            ->label('Title')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull()
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (string $operation, $state, Forms\Set $set): void {
                                if ($operation === 'create') {
                                    $set('slug', Str::slug((string) $state));
                                }
                            }),

                        Forms\Components\TextInput::make('slug')
                            ->label('معرف الرابط (SEO Slug)')
                            ->disabled()
                            ->dehydrated(false)
                            ->helperText('يتم إنشاؤه إنجليزياً وتلقائياً بحسب المتجر والتواريخ وهو ثابت دائماً لحماية الأرشفة.')
                            ->columnSpanFull(),

                        Forms\Components\DatePicker::make('valid_from')
                            ->label('Valid From')
                            ->required()
                            ->native(false)
                            ->displayFormat('Y-m-d'),

                        Forms\Components\DatePicker::make('valid_until')
                            ->label('Valid Until')
                            ->required()
                            ->native(false)
                            ->displayFormat('Y-m-d')
                            ->rule('after_or_equal:valid_from', true),

                        Forms\Components\Select::make('status')
                            ->label('Status')
                            ->options([
                                FlyerStatus::Draft->value => 'Draft',
                                FlyerStatus::PendingReview->value => 'Pending Review',
                                FlyerStatus::Published->value => 'Published',
                                FlyerStatus::Expired->value => 'Expired',
                            ])
                            ->enum(FlyerStatus::class)
                            ->required()
                            ->default(FlyerStatus::Draft->value)
                            ->native(false),

                        Forms\Components\Textarea::make('bluf_summary')
                            ->label('BLUF Summary (SEO/GEO)')
                            ->rows(3)
                            ->maxLength(500)
                            ->helperText('2-sentence Bottom Line Up Front for search and GEO.')
                            ->columnSpanFull(),

                        Forms\Components\Textarea::make('editorial_overview')
                            ->label('نص المقال التحريري ونقاط التوفير (مستخرج بالذكاء الاصطناعي - قابل للتعديل بالكامل)')
                            ->rows(8)
                            ->helperText('يمكنك تعديل أي نقطة أو رقم أو إضافة ملاحظات قبل النشر.')
                            ->columnSpanFull()
                            ->placeholder('سيتم توليده تلقائياً عبر Gemini بصيغة نقطية منظمة، يمكنك تعديله هنا...'),

                        Forms\Components\Select::make('applicable_governorates')
                            ->label('Applicable Governorates')
                            ->multiple()
                            ->options(self::governorateOptions())
                            ->searchable()
                            ->placeholder('All governorates (nationwide)')
                            ->helperText('Leave empty for nationwide.')
                            ->columnSpanFull(),

                        Forms\Components\TextInput::make('total_pages')
                            ->label('Total Pages')
                            ->numeric()
                            ->minValue(1)
                            ->default(1)
                            ->required(),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Pages')
                    ->description('Facebook image order — drag to reorder and fix sequence. Thumbnails from R2.')
                    ->schema([
                        Forms\Components\Repeater::make('pages')
                            ->label('Flyer Pages')
                            ->relationship('pages')
                            ->reorderable()
                            ->orderColumn('page_number')
                            ->collapsible()
                            ->cloneable()
                            ->itemLabel(fn (array $state): ?string => isset($state['page_number']) ? 'Page '.$state['page_number'] : 'New Page')
                            ->schema([
                                Forms\Components\FileUpload::make('image_path')
                                    ->label('Page Image')
                                    ->disk('r2')
                                    ->directory('flyers/pages')
                                    ->visibility('public')
                                    ->image()
                                    ->openable()
                                    ->downloadable()
                                    ->imagePreviewHeight('250')
                                    ->maxSize(5120)
                                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/jpg'])
                                    ->required()
                                    ->dehydrated(true)
                                    ->helperText(fn (): string => self::isR2Configured() ? 'Stored on R2 — سيتم ضغطه تلقائياً إلى WebP 1200px جودة 80.' : 'R2 not configured — stored locally (public/flyers/pages) كـ WebP.')
                                    ->saveUploadedFileUsing(function (TemporaryUploadedFile $file): string {
                                        $disk = 'r2';
                                        /** @var ImageOptimizerService $optimizer */
                                        $optimizer = app(ImageOptimizerService::class);
                                        try {
                                            return $optimizer->processUploadedFile($file, 'flyers/pages', $disk);
                                        } catch (\Throwable $e) {
                                            Log::warning('WebP conversion failed, storing original.', ['error' => $e->getMessage()]);
                                            // Fallback: store original as-is with canonical directory
                                            $path = $file->store('flyers/pages', $disk);

                                            $resolved = is_string($path) ? $path : 'flyers/pages/'.$file->getClientOriginalName();

                                            // Normalize duplicate prefix if any
                                            $resolved = ltrim($resolved, '/');
                                            $resolved = (string) preg_replace('#^flyers/pages/flyers/pages/#', 'flyers/pages/', $resolved);

                                            return $resolved;
                                        }
                                    }),

                                Forms\Components\Hidden::make('page_number')
                                    ->default(1),

                                Forms\Components\TextInput::make('width')
                                    ->label('Width')
                                    ->numeric()
                                    ->default(1200)
                                    ->required(),

                                Forms\Components\TextInput::make('height')
                                    ->label('Height')
                                    ->numeric()
                                    ->default(1600)
                                    ->required(),
                            ])
                            ->columns(2)
                            ->defaultItems(0)
                            ->addActionLabel('Add Page'),
                    ]),

                Forms\Components\Section::make('Items')
                    ->description('Product offers extracted via Gemini — inline editable.')
                    ->schema([
                        Forms\Components\Repeater::make('items')
                            ->label('Flyer Items')
                            ->relationship('items')
                            ->collapsible()
                            ->cloneable()
                            ->itemLabel(fn (array $state): ?string => $state['product_name'] ?? 'New Item')
                            ->schema([
                                Forms\Components\TextInput::make('product_name')
                                    ->label('Product Name')
                                    ->required()
                                    ->maxLength(255)
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(function (string $operation, $state, Forms\Set $set, Forms\Get $get): void {
                                        $currentSlug = $get('slug');
                                        if (blank($currentSlug) && filled($state)) {
                                            $set('slug', Str::slug((string) $state).'-'.substr(Str::ulid()->toString(), -4));
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
                                    ->createOptionForm([
                                        Forms\Components\TextInput::make('name')
                                            ->required()
                                            ->maxLength(255),
                                        Forms\Components\TextInput::make('slug')
                                            ->required()
                                            ->maxLength(255),
                                    ])
                                    ->placeholder('— No brand —'),

                                Forms\Components\Select::make('flyer_page_id')
                                    ->label('Page')
                                    ->relationship('flyerPage', 'page_number')
                                    ->getOptionLabelFromRecordUsing(fn ($record) => 'Page '.$record->page_number)
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
                            ])
                            ->columns(2)
                            ->defaultItems(0)
                            ->addActionLabel('Add Item'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('retailer.name')
                    ->label('Retailer')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),

                Tables\Columns\TextColumn::make('title')
                    ->label('Title')
                    ->searchable()
                    ->sortable()
                    ->limit(40)
                    ->tooltip(fn ($record) => $record->title),

                Tables\Columns\TextColumn::make('date_range')
                    ->label('Valid Range')
                    ->state(fn (Flyer $record): string => $record->valid_from?->format('Y-m-d').' → '.$record->valid_until?->format('Y-m-d'))
                    ->searchable(false)
                    ->sortable(false)
                    ->icon('heroicon-m-calendar')
                    ->copyable(false),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (FlyerStatus $state): string => match ($state) {
                        FlyerStatus::Draft => 'gray',
                        FlyerStatus::PendingReview => 'warning',
                        FlyerStatus::Published => 'success',
                        FlyerStatus::Expired => 'danger',
                    })
                    ->icon(fn (FlyerStatus $state): ?string => match ($state) {
                        FlyerStatus::Draft => 'heroicon-o-pencil-square',
                        FlyerStatus::PendingReview => 'heroicon-o-clock',
                        FlyerStatus::Published => 'heroicon-o-check-circle',
                        FlyerStatus::Expired => 'heroicon-o-x-circle',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('items_count')
                    ->label('Items')
                    ->counts('items')
                    ->sortable()
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('pages_count')
                    ->label('Pages')
                    ->counts('pages')
                    ->sortable()
                    ->badge()
                    ->color('gray')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('valid_from')
                    ->label('From')
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('valid_until')
                    ->label('Until')
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('retailer_id')
                    ->label('Retailer')
                    ->relationship('retailer', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        FlyerStatus::Draft->value => 'Draft',
                        FlyerStatus::PendingReview->value => 'Pending Review',
                        FlyerStatus::Published->value => 'Published',
                        FlyerStatus::Expired->value => 'Expired',
                    ]),

                Tables\Filters\Filter::make('date_range')
                    ->form([
                        Forms\Components\DatePicker::make('from'),
                        Forms\Components\DatePicker::make('until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'], fn (Builder $q, $date) => $q->where('valid_from', '>=', $date))
                            ->when($data['until'], fn (Builder $q, $date) => $q->where('valid_until', '<=', $date));
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),

                Tables\Actions\ViewAction::make(),

                Tables\Actions\Action::make('publishAndIndex')
                    ->label('Publish & Index')
                    ->color('success')
                    ->icon('heroicon-o-check-circle')
                    ->requiresConfirmation()
                    ->modalHeading('Publish flyer & ping IndexNow?')
                    ->modalDescription('This will set status to published and queue an IndexNow ping in background.')
                    ->visible(fn (Flyer $record): bool => $record->status !== FlyerStatus::Published)
                    ->action(function (Flyer $record): void {
                        $record->status = FlyerStatus::Published;
                        $record->save();

                        Notification::make()
                            ->title('Flyer published')
                            ->body('"'.$record->title.'" is now published and queued for IndexNow.')
                            ->success()
                            ->send();

                        PingIndexNowJob::dispatch(route('flyers.show', $record->slug));
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),

                    Tables\Actions\BulkAction::make('bulkPublish')
                        ->label('Publish selected')
                        ->color('success')
                        ->icon('heroicon-o-check-circle')
                        ->requiresConfirmation()
                        ->action(function (Collection $records): void {
                            foreach ($records as $record) {
                                $record->status = FlyerStatus::Published;
                                $record->save();
                                PingIndexNowJob::dispatch(route('flyers.show', $record->slug));
                            }

                            Notification::make()
                                ->title('Bulk publish queued')
                                ->body(count($records).' flyers published and IndexNow pings queued.')
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->defaultSort('valid_from', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFlyers::route('/'),
            'create' => Pages\CreateFlyer::route('/create'),
            'edit' => Pages\EditFlyer::route('/{record}/edit'),
            'view' => Pages\ViewFlyer::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['retailer'])->withCount(['items', 'pages']);
    }

    /**
     * @return array<string, string>
     */
    private static function governorateOptions(): array
    {
        return [
            'القاهرة' => 'القاهرة (Cairo)',
            'الجيزة' => 'الجيزة (Giza)',
            'الإسكندرية' => 'الإسكندرية (Alexandria)',
            'الدقهلية' => 'الدقهلية (Dakahlia)',
            'الشرقية' => 'الشرقية (Sharqia)',
            'المنوفية' => 'المنوفية (Menoufia)',
            'القليوبية' => 'القليوبية (Qalyubia)',
            'البحيرة' => 'البحيرة (Beheira)',
            'كفر الشيخ' => 'كفر الشيخ (Kafr El Sheikh)',
            'الغربية' => 'الغربية (Gharbia)',
            'المنيا' => 'المنيا (Minya)',
            'أسيوط' => 'أسيوط (Asyut)',
            'سوهاج' => 'سوهاج (Sohag)',
            'قنا' => 'قنا (Qena)',
            'أسوان' => 'أسوان (Aswan)',
            'الأقصر' => 'الأقصر (Luxor)',
            'البحر الأحمر' => 'البحر الأحمر (Red Sea)',
            'بورسعيد' => 'بورسعيد (Port Said)',
            'السويس' => 'السويس (Suez)',
            'الإسماعيلية' => 'الإسماعيلية (Ismailia)',
            'دمياط' => 'دمياط (Damietta)',
            'مطروح' => 'مطروح (Matrouh)',
            'الوادي الجديد' => 'الوادي الجديد (New Valley)',
            'شمال سيناء' => 'شمال سيناء (North Sinai)',
            'جنوب سيناء' => 'جنوب سيناء (South Sinai)',
            'بني سويف' => 'بني سويف (Beni Suef)',
            'الفيوم' => 'الفيوم (Fayoum)',
        ];
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
