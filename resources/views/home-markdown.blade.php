# مجلاتنا — عروض وتخفيضات السوبرماركت في مصر اليوم

**منصة مجلاتنا** هي مرجع أسعار وعروض السوبرماركت في مصر — نرصد مجلات كازيون، كارفور، بيم، هايبر وان، فتح الله وغيرها لحظة صدورها مع تفريغ أسعار السلع.

**الرابط الرئيسي:** {{ url('/') }}
**تاريخ التحديث:** {{ \Carbon\Carbon::now('Africa/Cairo')->format('Y-m-d H:i') }} بتوقيت القاهرة

## المتاجر النشطة ({{ $retailers->count() }})

@foreach($retailers as $retailer)
- [{{ $retailer->name }}]({{ route('retailers.show', $retailer->slug) }})
@endforeach

## أحدث المجلات السارية اليوم في مصر ({{ $flyers->total() }} مجلة)

@foreach($flyers as $flyer)
- [{{ $flyer->title }}]({{ route('flyers.show', $flyer->slug) }}) — {{ $flyer->retailer->name }} — من {{ \Carbon\Carbon::parse($flyer->valid_from)->format('Y-m-d') }} حتى {{ \Carbon\Carbon::parse($flyer->valid_until)->format('Y-m-d') }} — {{ $flyer->total_pages }} صفحة
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
