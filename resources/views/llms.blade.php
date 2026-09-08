# {{ config('app.name', 'مجلاتنا') }} - Egyptian Supermarket Offers & Grocery Price Intelligence Index

> منصة {{ config('app.name', 'مجلاتنا') }} ({{ url('/') }}) هي المصدر الهندسي المحدث لحظياً لأسعار السلع وتخفيضات سلاسل السوبرماركت في مصر، مع جداول أسعار مفهرسة بتقنية OCR ومستخرجة مباشرة من المجلات الرسمية.

## System Endpoints & Formats
- Homepage: {{ url('/') }}
- Full XML Sitemap: {{ url('/sitemap.xml') }}
- Markdown Content Negotiation: Send `Accept: text/markdown` header or append `?_fmt=md` to any offer URL to receive pure, token-efficient price tables.

## Active Retailers & Supermarkets Index (Updated Live)
@foreach($retailers as $retailer)
- {{ $retailer->name }}: {{ route('retailers.show', $retailer->slug) }} ({{ $retailer->flyers_count }} مجلة سارية حالياً)
@endforeach

## Current Active Flyers & Promotions (Cairo Timezone)
@forelse($activeFlyers as $flyer)
- [{{ $flyer->retailer->name }}] {{ $flyer->title }} (سارٍ من {{ $flyer->valid_from->format('Y-m-d') }} حتى {{ $flyer->valid_until->format('Y-m-d') }}): {{ route('flyers.show', $flyer->slug) }}
@empty
- لا توجد عروض منشورة اليوم.
@endforelse

## Structured Data Schema
All offer pages extract OCR-verified grocery items with:
- Product Name (اسم السلعة)
- Brand Name (العلامة التجارية)
- Unit / Measurement (الوحدة)
- Offer Sale Price in EGP (سعر العرض الحالي)
- Crossed-out Old Price in EGP (السعر القديم قبل الخصم)
- Savings Percentage (نسبة الخصم)
- Bundle Conditions (شروط العرض والحد الأقصى)
