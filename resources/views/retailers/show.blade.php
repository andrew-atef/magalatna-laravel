@php
    use App\Support\R2Url;
    $r2DiskUrl = R2Url::base();
    $logoUrl = $retailer->logo_path ? R2Url::asset($retailer->logo_path) : null;
    $canonicalUrl = $canonical ?? route('retailers.show', $retailer->slug);
    $year = \Carbon\Carbon::now('Africa/Cairo')->year;
    $metaTitle = $seoTitle ?? "عروض {$retailer->clean_name} في مصر اليوم {$year} | أحدث المجلات والتخفيضات";
    $metaDesc = $seoDescription ?? "تصفح أحدث عروض {$retailer->clean_name} في مصر اليوم {$year} - مجلات أسعار محدثة، خصومات حصرية ومقارنة أسعار السلع قبل الشراء.";
@endphp

<x-layouts.app
    :meta-title="$metaTitle"
    :meta-description="$metaDesc"
    :og-title="$metaTitle"
    :og-description="$metaDesc"
    :og-image="url('/img/og-cover.png')"
    og-type="website"
>
    @push('schema')
        @php
            $siteNameForSchema = config('app.name', 'مجلاتنا');
            $orgId = url('/') . '#organization';
            $websiteId = url('/') . '#website';
            $breadcrumbId = $canonicalUrl . '#breadcrumb';
            $webpageId = $canonicalUrl . '#webpage';
            $schemaMainEntity = null;
            if ($activeFlyers->isNotEmpty()) {
                $schemaMainEntity = [
                    '@type' => 'ItemList',
                    'name' => "مجلات عروض {$retailer->clean_name} السارية بمصر",
                    'numberOfItems' => $activeFlyers->total(),
                    'itemListElement' => $activeFlyers->getCollection()->values()->map(function ($flyer, $index) {
                        return [
                            '@type' => 'ListItem',
                            'position' => $index + 1,
                            'url' => route('flyers.show', $flyer->slug),
                            'name' => $flyer->title,
                        ];
                    })->all(),
                ];
            }
            $collectionPage = [
                '@type' => 'CollectionPage',
                '@id' => $webpageId,
                'name' => $metaTitle,
                'description' => $metaDesc,
                'url' => $canonicalUrl,
                'isPartOf' => ['@id' => $websiteId],
                'breadcrumb' => ['@id' => $breadcrumbId],
                'about' => [
                    '@type' => 'Organization',
                    'name' => $retailer->name,
                    'url' => $retailer->website_url ?: url('/'),
                    'logo' => $retailer->logo_path ? \App\Support\R2Url::asset($retailer->logo_path) : url('/favicon.svg'),
                ],
            ];
            if ($schemaMainEntity !== null) {
                $collectionPage['mainEntity'] = $schemaMainEntity;
            }
            $retailerGraph = [
                [
                    '@type' => 'Organization',
                    '@id' => $orgId,
                    'name' => $siteNameForSchema,
                    'url' => url('/'),
                    'logo' => [
                        '@type' => 'ImageObject',
                        'url' => url('/favicon.svg'),
                        'width' => 140,
                        'height' => 36,
                    ],
                    'areaServed' => 'EG',
                ],
                [
                    '@type' => 'WebSite',
                    '@id' => $websiteId,
                    'url' => url('/'),
                    'name' => $siteNameForSchema,
                    'publisher' => ['@id' => $orgId],
                ],
                [
                    '@type' => 'BreadcrumbList',
                    '@id' => $breadcrumbId,
                    'itemListElement' => [
                        ['@type' => 'ListItem', 'position' => 1, 'name' => 'الرئيسية', 'item' => url('/')],
                        ['@type' => 'ListItem', 'position' => 2, 'name' => $retailer->name, 'item' => $canonicalUrl],
                    ],
                ],
                $collectionPage,
            ];
            $retailerUnified = [
                '@context' => 'https://schema.org',
                '@graph' => $retailerGraph,
            ];
        @endphp
        <script type="application/ld+json">{!! json_encode($retailerUnified, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
    @endpush

    <!-- Breadcrumb - Navy -->
    <nav class="mb-4 text-xs font-semibold text-slate-500" aria-label="Breadcrumb">
        <ol class="flex items-center gap-1.5">
            <li><a href="{{ route('home') }}" class="hover:text-[#039652]">الرئيسية</a></li>
            <li>/</li>
            <li class="text-[#023b55] font-bold">{{ $retailer->name }}</li>
        </ol>
    </nav>

    <!-- Retailer Hero Section -->
    <section class="mb-8 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
        <div class="flex flex-col gap-6 sm:flex-row sm:items-center">
            <div class="flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                @if ($logoUrl)
                    <img src="{{ $logoUrl }}" alt="{{ $retailer->name }}" class="h-full w-full object-contain p-2">
                @else
                    <span class="text-2xl font-black text-[#039652]">{{ mb_substr($retailer->name, 0, 1) }}</span>
                @endif
            </div>
            <div class="flex-1">
                <h1 class="text-2xl font-black text-[#023b55] sm:text-3xl">عروض {{ $retailer->clean_name }} في مصر اليوم</h1>
                <p class="mt-2 max-w-3xl text-sm leading-relaxed text-slate-600">
                    تابع أحدث مجلات وعروض <span class="font-bold text-[#023b55]">{{ $retailer->name }}</span> في مصر — تحديث يومي لأسعار السلع، الخصومات الحصرية ومقارنة الأسعار قبل الشراء.
                    @if ($retailer->website_url)
                        <a href="{{ $retailer->website_url }}" target="_blank" rel="noopener" class="text-[#039652] hover:underline">الموقع الرسمي</a>
                    @endif
                </p>
                <div class="mt-4 flex flex-wrap gap-2">
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-[#039652]/10 px-3 py-1 text-xs font-bold text-[#039652] border border-[#039652]/20">
                        <span class="h-2 w-2 rounded-full bg-[#039652]"></span>
                        {{ $activeCount }} مجلة سارية الآن
                    </span>
                    <span class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">
                        آخر تحديث: {{ $retailer->updated_at->format('d/m/Y') }}
                    </span>
                    @if ($retailer->is_active)
                        <span class="inline-flex items-center rounded-full bg-[#023b55]/10 px-3 py-1 text-xs font-bold text-[#023b55] border border-[#023b55]/15">متجر نشط</span>
                    @endif
                </div>
            </div>
        </div>
    </section>

    <!-- Active Flyers Grid - Navy headings -->
    <section class="mb-12">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-lg font-black text-[#023b55] sm:text-xl">مجلات {{ $retailer->name }} السارية الآن</h2>
            <span class="text-xs font-semibold text-slate-500">{{ $activeFlyers->total() }} مجلة</span>
        </div>

        @if($activeFlyers->isNotEmpty())
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                @foreach ($activeFlyers as $flyer)
                    @php
                        $coverPage = $flyer->pages->first();
                        $coverUrl = $coverPage ? R2Url::asset($coverPage->image_path) : '/img/placeholder-flyer.png';
                    @endphp
                    @php
                            $today = \Carbon\Carbon::today('Africa/Cairo');
                            $from = \Carbon\Carbon::parse($flyer->valid_from, 'Africa/Cairo');
                            $until = \Carbon\Carbon::parse($flyer->valid_until, 'Africa/Cairo');
                            $isFuture = $from->isFuture();
                            $isPast = $until->isPast();
                        @endphp
                    <article class="group flex flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition hover:shadow-md">
                        <a href="{{ route('flyers.show', $flyer->slug) }}" class="relative aspect-[3/4] overflow-hidden bg-slate-100">
                            <img src="{{ $coverUrl }}" alt="{{ $flyer->title }}" width="600" height="800" loading="lazy" class="h-full w-full object-cover transition duration-300 group-hover:scale-105">
                            @if ($isFuture)
                                <span class="absolute right-2 top-2 rounded-lg bg-[#fcc023] px-2 py-1 text-[11px] font-bold text-slate-900 shadow">يبدأ {{ $from->format('d/m') }}</span>
                            @elseif ($isPast)
                                <span class="absolute right-2 top-2 rounded-lg bg-slate-600 px-2 py-1 text-[11px] font-bold text-white shadow">منتهي</span>
                            @else
                                <span class="absolute right-2 top-2 rounded-lg bg-[#039652] px-2 py-1 text-[11px] font-bold text-white shadow">سارٍ حتى {{ $until->format('d/m') }}</span>
                            @endif
                            <span class="absolute left-2 top-2 rounded-lg bg-slate-900/80 px-2 py-1 text-[11px] font-bold text-white backdrop-blur">{{ $flyer->total_pages }} صفحة</span>
                        </a>
                        <div class="flex flex-1 flex-col p-4">
                            <h3 class="line-clamp-2 text-sm font-bold text-[#023b55] group-hover:text-[#039652]">
                                <a href="{{ route('flyers.show', $flyer->slug) }}">{{ $flyer->title }}</a>
                            </h3>
                            <div class="mt-3 flex items-center justify-between border-t border-slate-100 pt-3 text-[11px]">
                                <span class="text-slate-500">{{ $from->format('d/m') }} → {{ $until->format('d/m/Y') }}</span>
                                @if ($isFuture)
                                    <span class="rounded bg-[#fcc023]/20 px-2 py-0.5 text-[10px] font-bold text-slate-900">يبدأ قريباً {{ $from->format('d/m') }}</span>
                                @elseif ($isPast)
                                    <span class="rounded bg-slate-100 px-2 py-0.5 text-[10px] font-bold text-slate-500">منتهي</span>
                                @else
                                    <span class="rounded bg-[#039652]/10 px-2 py-0.5 font-bold text-[#039652]">سارٍ الآن</span>
                                @endif
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
            <div class="mt-6">
                {{ $activeFlyers->links() }}
            </div>
        @else
            <div class="space-y-8">
                <div class="rounded-2xl border border-amber-500/20 bg-amber-50/60 p-6 text-center sm:p-8">
                    <span class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-amber-500/10 text-2xl">⏳</span>
                    <h2 class="mt-3 text-lg font-black text-[#023b55]">عروض {{ $retailer->clean_name }} الجديدة قيد التجهيز</h2>
                    <p class="mt-1 text-sm text-slate-600 max-w-md mx-auto">
                        لا توجد مجلة جديدة معلنة اليوم لـ {{ $retailer->name }}، ونقوم برفع العروض لحظة صدورها رسمياً. يمكنك مقارنة الأسعار من العروض السارية الآن في السلاسل الأخرى:
                    </p>
                </div>

                @if($otherActiveFlyers->isNotEmpty())
                    <div>
                        <h3 class="mb-4 text-base font-black text-[#023b55]">🔥 أقوى عروض السلاسل التجارية الأخرى المتاحة الآن بمصر:</h3>
                        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                            @foreach($otherActiveFlyers as $altFlyer)
                                @php
                                    $altCover = $altFlyer->pages->first();
                                    $altCoverUrl = $altCover ? R2Url::asset($altCover->image_path) : '/img/placeholder-flyer.png';
                                    $altFrom = \Carbon\Carbon::parse($altFlyer->valid_from, 'Africa/Cairo');
                                    $altUntil = \Carbon\Carbon::parse($altFlyer->valid_until, 'Africa/Cairo');
                                @endphp
                                <article class="group flex flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition hover:shadow-md">
                                    <a href="{{ route('flyers.show', $altFlyer->slug) }}" class="relative aspect-[3/4] overflow-hidden bg-slate-100">
                                        <img src="{{ $altCoverUrl }}" alt="{{ $altFlyer->title }}" width="600" height="800" loading="lazy" class="h-full w-full object-cover transition duration-300 group-hover:scale-105">
                                        <span class="absolute right-2 top-2 rounded-lg bg-[#023b55]/90 px-2 py-1 text-[10px] font-bold text-white">{{ $altFlyer->retailer->name }}</span>
                                        <span class="absolute left-2 top-2 rounded-lg bg-slate-900/80 px-2 py-1 text-[11px] font-bold text-white backdrop-blur">{{ $altFlyer->total_pages }} صفحة</span>
                                    </a>
                                    <div class="flex flex-1 flex-col p-4">
                                        <h3 class="line-clamp-2 text-sm font-bold text-[#023b55] group-hover:text-[#039652]">
                                            <a href="{{ route('flyers.show', $altFlyer->slug) }}">{{ $altFlyer->title }}</a>
                                        </h3>
                                        <div class="mt-3 flex items-center justify-between border-t border-slate-100 pt-3 text-[11px]">
                                            <span class="text-slate-500">{{ $altFrom->format('d/m') }} → {{ $altUntil->format('d/m/Y') }}</span>
                                            <span class="rounded bg-[#039652]/10 px-2 py-0.5 font-bold text-[#039652]">سارٍ الآن</span>
                                        </div>
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        @endif
    </section>

    <!-- Recently Expired (Price History) - Navy -->
    @if ($expiredFlyers->isNotEmpty())
        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-lg font-black text-[#023b55]">مجلات منتهية حديثاً — أرشيف أسعار {{ $retailer->name }} (آخر 30 يوم)</h2>
                <span class="text-xs font-semibold text-slate-500">{{ $expiredFlyers->count() }} مجلة</span>
            </div>
            <p class="mb-4 text-xs text-slate-500">للمقارنة ومعرفة تاريخ الأسعار قبل الشراء.</p>
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                @foreach ($expiredFlyers as $flyer)
                    @php
                        $coverPage = $flyer->pages->first();
                        $coverUrl = $coverPage ? R2Url::asset($coverPage->image_path) : '/img/placeholder-flyer.png';
                    @endphp
                    <article class="flex flex-col overflow-hidden rounded-xl border border-slate-200 bg-slate-50 opacity-90">
                        <a href="{{ route('flyers.show', $flyer->slug) }}" class="relative aspect-[3/4] overflow-hidden bg-slate-100">
                            <img src="{{ $coverUrl }}" alt="{{ $flyer->title }}" width="600" height="800" loading="lazy" class="h-full w-full object-cover grayscale">
                            <span class="absolute inset-0 bg-slate-900/10"></span>
                            <span class="absolute right-2 top-2 rounded-lg bg-slate-700 px-2 py-1 text-[11px] font-bold text-white">منتهي {{ \Carbon\Carbon::parse($flyer->valid_until)->format('d/m') }}</span>
                        </a>
                        <div class="p-3">
                            <h3 class="line-clamp-2 text-xs font-bold text-slate-700">
                                <a href="{{ route('flyers.show', $flyer->slug) }}">{{ $flyer->title }}</a>
                            </h3>
                        </div>
                    </article>
                @endforeach
            </div>
        </section>
    @endif
</x-layouts.app>
