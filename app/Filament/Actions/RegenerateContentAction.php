<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Jobs\RegenerateFlyerContentJob;
use Filament\Actions\Action;
use Filament\Actions\MountableAction;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action as TableAction;

/**
 * Shared "regenerate AI content" action — used by the FlyerResource table
 * row actions AND the EditFlyer / ViewFlyer header actions (one definition,
 * three surfaces). Dispatches RegenerateFlyerContentJob; the SEO slug is
 * never touched and a failed full re-OCR preserves existing items.
 *
 * IMPORTANT: table actions MUST be Filament\Tables\Actions\Action — the
 * table calls $action->table($this) on every registered action, a method
 * that only exists on the table subclass. make() returns the page-header
 * action; forTable() returns the table-safe variant. Both share one
 * configurator so the modal/behavior can never drift between surfaces.
 */
final class RegenerateContentAction
{
    /** Page header action (EditFlyer / ViewFlyer). */
    public static function make(): Action
    {
        $action = Action::make('regenerateContent');
        self::configure($action);

        return $action;
    }

    /** Table row action (FlyerResource list). */
    public static function forTable(): TableAction
    {
        $action = TableAction::make('regenerateContent');
        self::configure($action);

        return $action;
    }

    private static function configure(MountableAction $action): void
    {
        $action
            ->label('إعادة توليد المحتوى بالـ AI')
            ->icon('heroicon-o-sparkles')
            ->color('warning')
            ->modalHeading('إعادة توليد محتوى المجلة بالـ AI')
            ->modalDescription('لن يتغير رابط المجلة (slug) نهائياً حمايةً للأرشفة. في وضع القراءة الكاملة تُستخرج السلع من صور R2 المخزنة (وليس روابط فيسبوك المنتهية)، وتبقى السلع القديمة محفوظة كما هي إذا فشل الاستخراج أو لم يُرجع أي أسعار — لا يوجد أي فقدان للبيانات.')
            ->modalSubmitActionLabel('تأكيد إعادة التوليد')
            ->modalCancelActionLabel('إلغاء')
            ->form([
                Radio::make('mode')
                    ->label('نوع إعادة التوليد')
                    ->options([
                        'copy' => 'تحديث النصوص فقط (BLUF + المقال التحريري) — سريع، يعتمد على السلع الحالية',
                        'full' => 'إعادة قراءة كاملة بالـ OCR من الصور + تحديث النصوص — يعيد استخراج السلع بأقوى موديل متاح من صور R2',
                    ])
                    ->default('full')
                    ->required(),
            ])
            ->action(function (MountableAction $action, array $data): void {
                $record = $action->getRecord();

                RegenerateFlyerContentJob::dispatch(
                    (int) $record->id,
                    (string) ($data['mode'] ?? 'full'),
                )->afterCommit();

                Notification::make()
                    ->title('جاري إعادة التوليد في الخلفية وسيتم تحديث الكاش تلقائياً')
                    ->success()
                    ->send();
            });
    }
}
