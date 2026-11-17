@php
    // Canonical host STRICTLY from config('app.url') — never url()/request()
    // hosts: this content is cached for hours and may be rendered from a CLI
    // or admin-host request that would poison every absolute URL below.
    $baseUrl = rtrim((string) config('app.url'), '/');
@endphp
# مجلاتنا (Magalatna)

> منصة مجلاتنا ({{ $baseUrl }}) هي فهرس مصري محدَّث لحظياً لأسعار السلع وتخفيضات سلاسل السوبرماركت (كارفور، كازيون، بيم، هايبر وان وغيرها)، مع جداول أسعار لكل سلعة مستخرجة بتقنية OCR من المجلات الرسمية مباشرة.

## الصفحات الرئيسية (Core Pages)
- [الرئيسية]({{ $baseUrl }}): أحدث مجلات عروض السوبرماركت السارية في مصر اليوم مع أقوى التخفيضات المكتشفة.
- [خريطة الموقع XML]({{ $baseUrl }}/sitemap.xml): فهرس كامل ومحدَّث لجميع صفحات العروض القابلة للفهرسة للزحف الآلي.
- [صيغة Markdown لأي صفحة عرض]({{ $baseUrl }}): أضف الاستعلام `?_fmt=md` أو الترويسة `Accept: text/markdown` لأي رابط عرض للحصول على جدول أسعار نصي خالص موفِّر للتوكنز.

## المجلات السارية حالياً (Current Active Flyers)
@forelse($activeFlyers as $flyer)
    @php
        // Nearest-integer discount (fake-precision decimals like 24.69% read
        // like machine output) — same rule as BLUF/meta copy.
        $maxDiscount = (int) round((float) ($flyer->items_max_discount_percent ?? 0));
    @endphp
- [{{ $flyer->retailer->name }} — {{ $flyer->title }}]({{ $baseUrl.route('flyers.show', ['slug' => $flyer->slug], false) }}): سارٍ من {{ $flyer->valid_from->format('Y-m-d') }} حتى {{ $flyer->valid_until->format('Y-m-d') }} (بتوقيت القاهرة)، {{ $flyer->items_count }} سلعة مُوثَّقة بجدول أسعار كامل، أقوى خصم {{ $maxDiscount }}%.
@empty
- لا توجد عروض منشورة اليوم.
@endforelse

## المتاجر المتخصصة (Active Retailers)
@foreach($retailers as $retailer)
- [{{ $retailer->name }}]({{ $baseUrl.route('retailers.show', ['retailer' => $retailer->slug], false) }}): {{ $retailer->flyers_count }} مجلة سارية حالياً.
@endforeach

## حقول بيانات كل سلعة (Item Data Fields)
كل صفحة عرض تتضمن جدول أسعار موثوقاً مستخرجاً بالذكاء الاصطناعي من صور المجلة، ولكل سلعة الحقول التالية:
- اسم السلعة (Product Name)
- الماركة (Brand Name)
- الوحدة (Unit)
- سعر العرض الحالي بالجنيه المصري (Sale Price in EGP)
- السعر القديم قبل الخصم مشطوباً (Old Price in EGP)
- نسبة التوفير (Savings Percentage)
- شروط العرض والحد الأقصى (Bundle Conditions)
