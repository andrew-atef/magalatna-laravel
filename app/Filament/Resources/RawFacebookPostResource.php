<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\RawFacebookPostResource\Pages;
use App\Jobs\ProcessSinglePageJob;
use App\Models\Flyer;
use App\Models\RawFacebookPost;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

final class RawFacebookPostResource extends Resource
{
    protected static ?string $model = RawFacebookPost::class;

    protected static ?string $navigationIcon = 'heroicon-o-inbox-arrow-down';

    protected static ?string $navigationGroup = 'المراقبة والربط';

    protected static ?string $navigationLabel = 'وارد فيسبوك الخام';

    protected static ?string $modelLabel = 'منشور فيسبوك';

    protected static ?string $pluralModelLabel = 'وارد فيسبوك الخام';

    protected static ?int $navigationSort = 10;

    public static function getNavigationBadge(): ?string
    {
        $count = RawFacebookPost::whereIn('status', ['pending', 'rejected'])->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        $rejected = RawFacebookPost::where('status', 'rejected')->count();

        return $rejected > 0 ? 'danger' : 'warning';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('بيانات المنشور')
                    ->schema([
                        Forms\Components\Select::make('retailer_id')
                            ->label('المتجر')
                            ->relationship('retailer', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),

                        Forms\Components\TextInput::make('facebook_post_id')
                            ->label('Facebook Post ID')
                            ->required()
                            ->maxLength(255),

                        Forms\Components\Textarea::make('post_text')
                            ->label('نص المنشور')
                            ->rows(6)
                            ->columnSpanFull(),

                        Forms\Components\KeyValue::make('image_urls')
                            ->label('روابط الصور')
                            ->columnSpanFull(),

                        Forms\Components\DateTimePicker::make('published_at')
                            ->label('تاريخ النشر')
                            ->native(false),

                        Forms\Components\Select::make('status')
                            ->label('الحالة')
                            ->options([
                                'pending' => 'قيد الانتظار',
                                'accepted' => 'مقبول',
                                'rejected' => 'مرفوض',
                                'manually_approved' => 'موافق عليه يدوياً',
                            ])
                            ->required()
                            ->native(false),

                        Forms\Components\Textarea::make('rejection_reason')
                            ->label('سبب الرفض')
                            ->rows(3)
                            ->columnSpanFull(),

                        Forms\Components\KeyValue::make('ai_classification')
                            ->label('تصنيف AI')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('retailer.name')
                    ->label('المتجر')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('gray')
                    ->icon('heroicon-o-building-storefront'),

                Tables\Columns\TextColumn::make('post_text')
                    ->label('نص المنشور')
                    ->limit(80)
                    ->tooltip(fn (RawFacebookPost $record): ?string => $record->post_text)
                    ->searchable()
                    ->wrap(),

                Tables\Columns\TextColumn::make('images_count')
                    ->label('عدد الصور')
                    ->state(fn (RawFacebookPost $record): int => is_array($record->image_urls) ? count($record->image_urls) : 0)
                    ->badge()
                    ->color('gray')
                    ->sortable(false),

                Tables\Columns\ImageColumn::make('thumbnail')
                    ->label('الصورة')
                    ->state(function (RawFacebookPost $record): ?string {
                        $urls = $record->image_urls;
                        if (is_array($urls) && count($urls) > 0) {
                            return (string) $urls[0];
                        }

                        return null;
                    })
                    ->circular(false)
                    ->size(48)
                    ->defaultImageUrl(url('/images/placeholder-retailer.png')),

                Tables\Columns\TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'warning',
                        'accepted' => 'success',
                        'rejected' => 'danger',
                        'manually_approved' => 'info',
                        default => 'gray',
                    })
                    ->icon(fn (string $state): ?string => match ($state) {
                        'pending' => 'heroicon-o-clock',
                        'accepted' => 'heroicon-o-check-circle',
                        'rejected' => 'heroicon-o-x-circle',
                        'manually_approved' => 'heroicon-o-hand-raised',
                        default => null,
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending' => 'قيد الانتظار',
                        'accepted' => 'مقبول',
                        'rejected' => 'مرفوض',
                        'manually_approved' => 'موافق يدوياً',
                        default => $state,
                    })
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('rejection_reason')
                    ->label('سبب الرفض')
                    ->limit(60)
                    ->tooltip(fn (RawFacebookPost $record): ?string => $record->rejection_reason)
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('published_at')
                    ->label('تاريخ النشر')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاريخ الاستلام')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('retailer_id')
                    ->label('المتجر')
                    ->relationship('retailer', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('status')
                    ->label('الحالة')
                    ->options([
                        'pending' => 'قيد الانتظار',
                        'accepted' => 'مقبول',
                        'rejected' => 'مرفوض',
                        'manually_approved' => 'موافق يدوياً',
                    ])
                    ->native(false),
            ])
            ->actions([
                Tables\Actions\Action::make('viewPost')
                    ->label('عرض المحتوى')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading(fn (RawFacebookPost $record): string => 'منشور ' . $record->retailer->name . ' — ' . $record->facebook_post_id)
                    ->modalWidth('5xl')
                    ->modalContent(function (RawFacebookPost $record): \Illuminate\View\View {
                        return view('filament.modals.raw-post-view', ['record' => $record]);
                    })
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('إغلاق'),

                Tables\Actions\Action::make('forceApprove')
                    ->label('اعتماد وتحويل لمجلة')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (RawFacebookPost $record): bool => in_array($record->status, ['rejected', 'pending'], true))
                    ->requiresConfirmation()
                    ->modalHeading('اعتماد المنشور وتحويله لمجلة عروض')
                    ->modalDescription('سيتم إنشاء مجلة جديدة وربطها بهذا المنشور وبدء قراءة المنتجات عبر Gemini.')
                    ->form([
                        Forms\Components\TextInput::make('flyer_title')
                            ->label('عنوان المجلة')
                            ->required()
                            ->maxLength(255)
                            ->default(function (RawFacebookPost $record): string {
                                $aiTitle = $record->ai_classification['flyer_title'] ?? null;
                                if (is_string($aiTitle) && trim($aiTitle) !== '') {
                                    return trim($aiTitle);
                                }
                                $text = trim((string) $record->post_text);
                                $snippet = mb_substr($text, 0, 60);

                                return $snippet !== '' ? $snippet : 'عروض ' . $record->retailer->name;
                            })
                            ->helperText('سيتم استخدامه كعنوان SEO للمجلة.'),

                        Forms\Components\DatePicker::make('valid_from')
                            ->label('صالح من')
                            ->native(false)
                            ->required()
                            ->default(now('Africa/Cairo')->toDateString())
                            ->displayFormat('Y-m-d'),

                        Forms\Components\DatePicker::make('valid_until')
                            ->label('صالح حتى')
                            ->native(false)
                            ->required()
                            ->default(now('Africa/Cairo')->addDays(7)->toDateString())
                            ->displayFormat('Y-m-d')
                            ->rule('after_or_equal:valid_from'),
                    ])
                    ->action(function (RawFacebookPost $record, array $data): void {
                        try {
                            $validFrom = (string) $data['valid_from'];
                            $validUntil = (string) $data['valid_until'];
                            $title = trim((string) $data['flyer_title']);

                            if ($title === '') {
                                $title = 'عروض ' . $record->retailer->name;
                            }

                            // Generate slug via service for consistency
                            $slugService = app(\App\Services\FlyerSlugService::class);
                            $slug = $slugService->generate(
                                retailerSlug: $record->retailer->slug,
                                validFromDate: $validFrom,
                                validUntilDate: $validUntil
                            );

                            // Check for existing flyer consolidation (same period)
                            $existing = Flyer::where('retailer_id', $record->retailer_id)
                                ->where('valid_from', $validFrom)
                                ->where('valid_until', $validUntil)
                                ->whereIn('status', [\App\Enums\FlyerStatus::Draft, \App\Enums\FlyerStatus::PendingReview, \App\Enums\FlyerStatus::Published])
                                ->first();

                            if ($existing !== null) {
                                $flyer = $existing;
                                $startPage = (int) ($flyer->pages()->max('page_number') ?? 0);
                                $flyer->total_pages = (int) $flyer->total_pages + count((array) $record->image_urls);
                                $flyer->save();
                            } else {
                                $flyer = Flyer::create([
                                    'retailer_id' => $record->retailer_id,
                                    'title' => $title,
                                    'slug' => $slug,
                                    'valid_from' => $validFrom,
                                    'valid_until' => $validUntil,
                                    'status' => \App\Enums\FlyerStatus::Draft,
                                    'total_pages' => count((array) $record->image_urls),
                                ]);
                                $startPage = 0;
                            }

                            $record->update([
                                'status' => 'manually_approved',
                                'flyer_id' => $flyer->id,
                            ]);

                            $imageUrls = is_array($record->image_urls) ? array_values($record->image_urls) : [];

                            foreach ($imageUrls as $index => $url) {
                                $pageNumber = $startPage + $index + 1;
                                ProcessSinglePageJob::dispatch(
                                    flyerId: $flyer->id,
                                    imageUrl: (string) $url,
                                    pageNumber: $pageNumber,
                                );
                            }

                            Notification::make()
                                ->title('تم تحويل المنشور إلى مجلة عروض بنجاح وجارٍ قراءة المنتجات.')
                                ->body('المجلة: ' . $flyer->title . ' — سيتم استخراج المنتجات عبر Gemini خلال دقائق.')
                                ->success()
                                ->send();
                        } catch (Throwable $e) {
                            Log::error('Force approve failed for RawFacebookPost.', [
                                'raw_post_id' => $record->id,
                                'error' => $e->getMessage(),
                                'trace' => $e->getTraceAsString(),
                            ]);

                            Notification::make()
                                ->title('فشل اعتماد المنشور')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->poll('15s');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRawFacebookPosts::route('/'),
            'view' => Pages\ViewRawFacebookPost::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['retailer', 'flyer']);
    }
}
