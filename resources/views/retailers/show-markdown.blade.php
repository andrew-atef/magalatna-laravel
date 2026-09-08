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

@if($expiredFlyers->isNotEmpty())
## أرشيف عروض {{ $retailer->name }} — آخر 30 يوم

@foreach($expiredFlyers as $flyer)
- [{{ $flyer->title }}]({{ route('flyers.show', $flyer->slug) }}) — انتهى {{ \Carbon\Carbon::parse($flyer->valid_until)->format('Y-m-d') }}
@endforeach
@endif

---
*المصدر: مجلاتنا (magalatna.com) — أرشيف أسعار السوبرماركت في مصر.*
*رابط المتجر: {{ route('retailers.show', $retailer->slug) }}*
