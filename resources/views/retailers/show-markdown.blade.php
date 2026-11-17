# عروض {{ $retailer->name }} في مصر — مجلاتنا

**المتجر:** {{ $retailer->name }}
**الحالة:** {{ $retailer->is_active ? 'نشط' : 'غير نشط' }}
**الموقع الرسمي:** {{ $retailer->website_url ?? '—' }}
**عدد المجلات السارية اليوم:** {{ $activeCount }}
**رابط المتجر على مجلاتنا:** {{ route('retailers.show', $retailer->slug) }}

## المجلات السارية اليوم ({{ $activeFlyers->total() }} مجلة)

@foreach($activeFlyers as $flyer)
- [{{ $flyer->title }}]({{ route('flyers.show', $flyer->slug) }}) — من {{ \Carbon\Carbon::parse($flyer->valid_from)->format('Y-m-d') }} حتى {{ \Carbon\Carbon::parse($flyer->valid_until)->format('Y-m-d') }} — {{ $flyer->total_pages }} صفحة
@endforeach

@if($activeFlyers->isEmpty())
لا توجد مجلات سارية حالياً لـ {{ $retailer->name }}.
@endif

@if($topRetailerItems->isNotEmpty())
## أبرز أسعار وعروض {{ $retailer->clean_name }} السارية اليوم

أسعار فعلية لسلع مختارة من مجلات {{ $retailer->name }} السارية الآن، مرتبة من الأعلى توفيراً:

| اسم السلعة | الوحدة | سعر العرض (ج.م) | السعر القديم (ج.م) | نسبة التوفير |
|---|---|---|---|---|
@foreach($topRetailerItems as $item)
@php
    // Escape pipe/newline so a product name can never break the table grid.
    $mdItemName = str_replace(['|', "\r", "\n"], ['\\|', ' ', ' '], (string) $item->product_name);
@endphp
| [{{ $mdItemName }}]({{ rtrim((string) config('app.url'), '/') }}{{ route('flyers.show', ['slug' => $item->flyer->slug], false) }}#item-{{ $item->id }}) | {{ $item->unit ?? '—' }} | {{ number_format((float) $item->sale_price, 2) }} | {{ $item->old_price !== null ? number_format((float) $item->old_price, 2) : '—' }} | {{ $item->discount_percent !== null ? round((float) $item->discount_percent).'%' : '—' }} |
@endforeach

أسعار كل السلع مع الروابط المباشرة في صفحة كل مجلة أعلاه.
@endif

@if($expiredFlyers->isNotEmpty())
## أرشيف عروض {{ $retailer->name }} — آخر 30 يوم

@foreach($expiredFlyers as $flyer)
- [{{ $flyer->title }}]({{ route('flyers.show', $flyer->slug) }}) — انتهى {{ \Carbon\Carbon::parse($flyer->valid_until)->format('Y-m-d') }}
@endforeach
@endif

---
*المصدر: مجلاتنا (magalatna.com) — أرشيف أسعار السوبرماركت في مصر.*
*رابط المتجر: {{ route('retailers.show', $retailer->slug) }}*
