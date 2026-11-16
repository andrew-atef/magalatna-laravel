# مجلاتنا — عروض وتخفيضات السوبرماركت في مصر اليوم

**منصة مجلاتنا** هي مرجع أسعار وعروض السوبرماركت في مصر — نرصد مجلات كازيون، كارفور، بيم، هايبر وان، فتح الله وغيرها لحظة صدورها مع تفريغ أسعار السلع.

@php
    // Canonical host from config — CLI/non-canonical requests never poison links.
    $mdBaseUrl = rtrim((string) config('app.url'), '/');
@endphp
**الرابط الرئيسي:** {{ $mdBaseUrl }}/
**تاريخ التحديث:** {{ \Carbon\Carbon::now('Africa/Cairo')->format('Y-m-d H:i') }} بتوقيت القاهرة

@if(isset($searchQuery) && trim((string) $searchQuery) !== '' && isset($matchingItems) && $matchingItems->isNotEmpty())
## نتائج البحث عن "{{ $searchQuery }}" ({{ $matchingItems->total() }} سلعة)

| السلعة | المتجر | سعر العرض (ج.م) | السعر القديم (ج.م) |
| :--- | :--- | :--- | :--- |
@foreach($matchingItems as $item)
| {{ str_replace('|', '\|', (string) $item->product_name) }} | {{ str_replace('|', '\|', (string) ($item->flyer->retailer?->name ?? '—')) }} | {{ number_format((float) $item->sale_price, 2) }} | {{ $item->old_price ? number_format((float) $item->old_price, 2) : '—' }} |
@endforeach
@endif

## المتاجر النشطة ({{ $retailers->count() }})

@foreach($retailers as $retailer)
- [{{ $retailer->name }}]({{ $mdBaseUrl.route('retailers.show', ['retailer' => $retailer->slug], false) }})
@endforeach

## أحدث المجلات السارية اليوم في مصر ({{ $flyers->total() }} مجلة)

@foreach($flyers as $flyer)
- [{{ $flyer->title }}]({{ $mdBaseUrl.route('flyers.show', ['slug' => $flyer->slug], false) }}) — {{ $flyer->retailer?->name ?? '—' }} — من {{ \Carbon\Carbon::parse($flyer->valid_from)->format('Y-m-d') }} حتى {{ \Carbon\Carbon::parse($flyer->valid_until)->format('Y-m-d') }} — {{ $flyer->pages_count ?? $flyer->total_pages }} صفحة
@endforeach

@if($flyers->isEmpty())
لا توجد مجلات سارية حالياً.
@endif

@if(isset($hotItems) && $hotItems->isNotEmpty())
## أقوى تخفيضات السلع والمنتجات اليوم

| السلعة | المتجر | سعر العرض (ج.م) | السعر القديم (ج.م) | نسبة الخصم | رابط العرض |
| :--- | :--- | :--- | :--- | :--- | :--- |
@foreach($hotItems as $item)
| {{ str_replace('|', '\|', (string) $item->product_name) }} | {{ $item->flyer->retailer->name }} | {{ number_format((float) $item->sale_price, 2) }} | {{ $item->old_price ? number_format((float) $item->old_price, 2) : '—' }} | {{ $item->discount_percent ? round((float) $item->discount_percent).'%' : '—' }} | [عرض]({{ route('flyers.show', $item->flyer->slug) }}#item-{{ $item->id }}) |
@endforeach
@endif

---
*المصدر: مجلاتنا (magalatna.com) — تحديث يومي لأسعار السوبرماركت.*
*تصفح النسخة الويب: {{ url('/') }}*
