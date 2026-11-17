# {{ $flyer->title }}

**المتجر:** {{ $flyer->retailer?->name ?? '—' }}
**تاريخ السريان:** من {{ $flyer->valid_from?->format('Y-m-d') ?? '—' }} حتى {{ $flyer->valid_until?->format('Y-m-d') ?? '—' }} (بتوقيت القاهرة)
**الحالة:** {{ $flyer->isExpired() ? 'منتهي الصلاحية' : 'سارٍ الآن' }}
**عدد الصفحات:** {{ $flyer->pages_count ?? $flyer->total_pages }}
@if ($flyer->isExpired() && ! empty($latestActiveFlyer))

**مجلة سارية جديدة لنفس المتجر:** [{{ $latestActiveFlyer->title }}]({{ rtrim((string) config('app.url'), '/').route('flyers.show', ['slug' => $latestActiveFlyer->slug], false) }})
@endif

## خلاصة العرض (BLUF)
{{ $flyer->bluf_summary }}

@if ($flyer->isExpired())
## ملاحظة الأرشيف
هذا العرض انتهت فترة سريانه؛ الأسعار الواردة أعلاه محفوظة كأرشيف تاريخي لمقارنة تطور أسعار السوبرماركت في مصر.
@else
## نظرة تحريرية وأبرز نقاط التوفير
{{ $flyer->editorial_overview }}
@endif

## جدول السلع والأسعار المفصلة
| اسم السلعة | الماركة | الوحدة | سعر العرض (ج.م) | السعر القديم (ج.م) | نسبة الخصم | شروط العرض |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- |
@foreach($flyer->items as $item)
| {{ str_replace('|', '\|', (string) $item->product_name) }} | {{ str_replace('|', '\|', (string) ($item->brand?->name ?? '—')) }} | {{ $item->unit ?? 'قطعة' }} | {{ number_format((float) $item->sale_price, 2) }} | {{ $item->old_price ? number_format((float) $item->old_price, 2) : '—' }} | {{ $item->discount_percent ? round((float) $item->discount_percent).'%' : '—' }} | {{ str_replace('|', '\|', (string) ($item->bundle_condition ?? '—')) }} |
@endforeach

---
*المصدر الرسمي: مجلاتنا (magalatna.com) — مرجع أسعار وعروض السوبرماركت في مصر.*
*رابط العرض على الويب: {{ route('flyers.show', $flyer->slug) }}*
