@php
    use App\Support\R2Url;
    $r2DiskUrl = R2Url::base();
    $logoUrl = $retailer->logo_path ? R2Url::asset($retailer->logo_path) : null;
    // og:image resolved by the controller: LONGEST-LIVING active flyer's
    // Discover composite (1280x720) → cover master → logo → site fallback.
    $ogImage = $ogImage ?? $logoUrl ?: url('/img/og-cover.png');
    // The Discover composite is a native 1280x720 canvas — declare the exact
    // dimensions so crawlers size the preview without fetching the image.
    $ogImageIsDiscover = (bool) ($ogImageIsDiscover ?? false);
    // Single Cairo "today" string — the ONLY date boundary used everywhere
    // below (badges, card states) so a tz drift can never split states.
    $cairoToday = \Carbon\Carbon::today('Africa/Cairo')->toDateString();
    // Canonical strictly from config('app.url') + relative route.
    // Paginated hubs (?page=2+) carry their OWN self-canonical with ?page=N
    // (noindex,follow robots is emitted below unchanged).
    $baseUrl = rtrim((string) config('app.url'), '/');
    $homeUrl = $baseUrl.'/';
    $canonicalUrl = $canonical ?? ($baseUrl.route('retailers.show', ['retailer' => $retailer->slug], false));
    $isPaginated = (int) request('page', 1) > 1;
    if ($isPaginated) {
        $canonicalUrl .= '?page='.(int) request('page');
    }
    $robotsMeta = $isPaginated ? 'noindex, follow' : 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1';
    $year = \Carbon\Carbon::now('Africa/Cairo')->year;
    $metaTitle = $seoTitle ?? "عروض {$retailer->clean_name} في مصر اليوم {$year} | أحدث المجلات والتخفيضات";
    $metaDesc = $seoDescription ?? "تصفح أحدث عروض {$retailer->clean_name} في مصر اليوم {$year} - مجلات أسعار محدثة، خصومات حصرية ومقارنة أسعار السلع قبل الشراء.";
    // Evergreen hub data (FAQ + freshness) come straight from the model so
    // the schema and the visible section can NEVER drift apart.
    $faqsList = $retailer->faqs_list;
    $effectiveLastmod = $retailer->effective_lastmod;
    $upcomingCount = (int) ($upcomingCount ?? 0);
    $activeTodayCount = (int) ($activeTodayCount ?? $activeCount ?? 0);
@endphp

<x-layouts.app
    :meta-title="$metaTitle"
    :meta-description="$metaDesc"
    :og-title="$metaTitle"
    :og-description="$metaDesc"
    :og-image="$ogImage"
    :og-image-width="$ogImageIsDiscover ? 1280 : null"
    :og-image-height="$ogImageIsDiscover ? 720 : null"
    og-type="website"
    :robots="$robotsMeta"
    :canonical="$canonicalUrl"
>
    @push('schema')
        @php
            $siteNameForSchema = config('app.name', 'مجلاتنا');
            $orgId = $baseUrl . '/#organization';
            $websiteId = $baseUrl . '/#website';
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
                'publisher' => ['@id' => $orgId],
                'about' => [
                    '@type' => 'Organization',
                    'name' => $retailer->name,
                    'url' => $retailer->website_url ?: $baseUrl . '/',
                    'logo' => $retailer->logo_path ? \App\Support\R2Url::asset($retailer->logo_path) : $baseUrl . '/logo.webp',
                ],
            ];
            if ($schemaMainEntity !== null) {
                $collectionPage['mainEntity'] = $schemaMainEntity;
            }
            // Real social profiles only — NEVER emit an empty "sameAs": []
            // (Rich Results flags an empty array as an invalid property).
            $siteSocialLinks = array_values(array_filter((array) config('app.social_links', [])));
            $siteOrganization = [
                '@type' => 'Organization',
                '@id' => $orgId,
                'name' => $siteNameForSchema,
                'url' => $baseUrl . '/',
                'logo' => [
                    '@type' => 'ImageObject',
                    // The REAL 140x36 logo — favicon.svg is a square icon and
                    // fails Google's Organization logo image guidelines.
                    'url' => $baseUrl . '/logo.webp',
                    'width' => 140,
                    'height' => 36,
                ],
                'areaServed' => 'EG',
            ];
            if ($siteSocialLinks !== []) {
                $siteOrganization['sameAs'] = $siteSocialLinks;
            }
            // FAQPage node from $retailer->faqs_list (admin-curated or smart
            // defaults — the SAME source the visible FAQ section renders) —
            // keeps Google's FAQ rich results in sync with what users see.
            $faqSchemaNode = [
                '@type' => 'FAQPage',
                '@id' => $canonicalUrl.'#faqpage',
                'mainEntity' => collect($faqsList)->map(static fn (array $faq): array => [
                    '@type' => 'Question',
                    'name' => (string) $faq['question'],
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => (string) $faq['answer'],
                    ],
                ])->values()->all(),
            ];
            $retailerGraph = [
                $siteOrganization,
                [
                    '@type' => 'WebSite',
                    '@id' => $websiteId,
                    'url' => $baseUrl . '/',
                    'name' => $siteNameForSchema,
                    'publisher' => ['@id' => $orgId],
                ],
                [
                    '@type' => 'BreadcrumbList',
                    '@id' => $breadcrumbId,
                    'itemListElement' => [
                        ['@type' => 'ListItem', 'position' => 1, 'name' => 'الرئيسية', 'item' => $homeUrl],
                        ['@type' => 'ListItem', 'position' => 2, 'name' => $retailer->name, 'item' => $canonicalUrl],
                    ],
                ],
                $collectionPage,
                $faqSchemaNode,
            ];
            $retailerUnified = [
                '@context' => 'https://schema.org',
                '@graph' => $retailerGraph,
            ];
        @endphp
        <script type="application/ld+json">{!! json_encode($retailerUnified, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) !!}</script>
    @endpush

    <!-- Breadcrumb - Navy -->
    <nav class="mb-4 text-xs font-semibold text-slate-500" aria-label="Breadcrumb">
        <ol class="flex items-center gap-1.5">
            <li><a href="{{ $homeUrl }}" class="hover:text-[#039652]">الرئيسية</a></li>
            <li>/</li>
            <li class="text-[#023b55] font-bold">{{ $retailer->name }}</li>
        </ol>
    </nav>

    <!-- Retailer Hero Section -->
    <section class="mb-8 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
        <div class="flex flex-col gap-6 sm:flex-row sm:items-center">
            <div class="flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                @if ($logoUrl)
                    <img src="{{ $logoUrl }}" alt="{{ $retailer->name }}" width="80" height="80" class="h-full w-full object-contain p-2">
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
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-[#039652]/10 px-3 py-1 text-xs font-bold text-[#027a42] border border-[#039652]/20">
                        <span class="h-2 w-2 rounded-full bg-[#039652]"></span>
                        {{ \App\Support\ArabicDateHelper::pluralizeCount($activeTodayCount, 'مجلة سارية اليوم', 'مجلتان ساريتان اليوم', 'مجلات سارية اليوم', 'مجلة سارية اليوم') }}
                    </span>
                    @if ($upcomingCount > 0)
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-[#fcc023]/15 px-3 py-1 text-xs font-bold text-slate-900 border border-[#fcc023]/40">
                            ⏳ {{ \App\Support\ArabicDateHelper::pluralizeCount($upcomingCount, 'مجلة تبدأ قريباً', 'مجلتان تبدآن قريباً', 'مجلات تبدأ قريباً', 'مجلة تبدأ قريباً') }}
                        </span>
                    @endif
                    <span class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">
                        آخر تحديث: {{ $effectiveLastmod->format('d/m/Y') }}
                    </span>
                    @if (trim((string) $retailer->hotline) !== '')
                        <span class="inline-flex items-center gap-1 rounded-full bg-[#023b55]/10 px-3 py-1 text-xs font-bold text-[#023b55] border border-[#023b55]/15">
                            📞 الخط الساخن: <a href="tel:{{ $retailer->hotline }}" class="underline decoration-dotted hover:text-[#039652]" dir="ltr">{{ $retailer->hotline }}</a>
                        </span>
                    @endif
                    @if ((int) ($retailer->branches_count ?? 0) > 0)
                        <span class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">
                            🏪 {{ $retailer->branches_count }}+ فرع بمصر
                        </span>
                    @endif
                    @if ($retailer->is_active)
                        <span class="inline-flex items-center rounded-full bg-[#023b55]/10 px-3 py-1 text-xs font-bold text-[#023b55] border border-[#023b55]/15">متجر نشط</span>
                    @endif
                </div>
                @if (trim((string) $retailer->schedule_summary) !== '')
                    <div class="mt-4 rounded-xl border border-[#039652]/20 bg-[#039652]/5 px-4 py-3 text-xs leading-relaxed text-slate-700">
                        <span class="font-black text-[#027a42]">📅 مواعيد نزول العروض:</span>
                        {{ $retailer->schedule_summary }}
                    </div>
                @endif
            </div>
        </div>
    </section>

    <!-- Active Flyers Grid - Navy headings -->
    <section class="mb-12">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-lg font-black text-[#023b55] sm:text-xl">مجلات {{ $retailer->name }} السارية الآن</h2>
            <span class="text-xs font-semibold text-slate-500">{{ \App\Support\ArabicDateHelper::pluralizeCount($activeFlyers->total(), 'مجلة', 'مجلتان', 'مجلات', 'مجلة') }}</span>
        </div>

        @if($activeFlyers->isNotEmpty())
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                @foreach ($activeFlyers as $flyer)
                    @php
                        $coverPage = $flyer->coverPage;
                        $coverUrl = R2Url::cardThumb($coverPage);
                    @endphp
                    @php
                            $from = \Carbon\Carbon::parse($flyer->valid_from, 'Africa/Cairo');
                            $until = \Carbon\Carbon::parse($flyer->valid_until, 'Africa/Cairo');
                            // String-date comparison in Africa/Cairo: a flyer
                            // starting TODAY is NEVER "future" — only one
                            // strictly after today is.
                            $isFuture = $from->toDateString() > $cairoToday;
                            $isPast = $flyer->isExpired();
                            $isActiveToday = ! $isFuture && ! $isPast;
                        @endphp
                    <article class="group flex flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition hover:shadow-md">
                        <a href="{{ route('flyers.show', $flyer->slug) }}" class="relative aspect-[3/4] overflow-hidden bg-slate-100">
                            <img src="{{ $coverUrl }}" alt="{{ $flyer->title }}" width="400" height="533" @if ($loop->first) loading="eager" fetchpriority="high" decoding="async" @else loading="lazy" decoding="async" @endif class="h-full w-full object-cover transition duration-300 group-hover:scale-105">
                            @if ($isFuture)
                                <span class="absolute right-2 top-2 rounded-lg bg-[#fcc023] px-2 py-1 text-[11px] font-bold text-slate-900 shadow">يبدأ {{ $from->format('d/m') }}</span>
                            @elseif ($isPast)
                                <span class="absolute right-2 top-2 rounded-lg bg-slate-600 px-2 py-1 text-[11px] font-bold text-white shadow">منتهي</span>
                            @else
                                <span class="absolute right-2 top-2 rounded-lg bg-[#039652] px-2 py-1 text-[11px] font-bold text-white shadow">سارٍ حتى {{ $until->format('d/m') }}</span>
                            @endif
                            <span class="absolute left-2 top-2 rounded-lg bg-slate-900/80 px-2 py-1 text-[11px] font-bold text-white backdrop-blur">{{ \App\Support\ArabicDateHelper::pluralizeCount((int) ($flyer->pages_count ?? $flyer->total_pages), 'صفحة', 'صفحتان', 'صفحات', 'صفحة') }}</span>
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
                                @elseif ($isActiveToday)
                                    <span class="rounded bg-[#039652]/10 px-2 py-0.5 font-bold text-[#027a42]">سارٍ الآن</span>
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
                                    $altCover = $altFlyer->coverPage;
                                    $altCoverUrl = R2Url::cardThumb($altCover);
                                    $altFrom = \Carbon\Carbon::parse($altFlyer->valid_from, 'Africa/Cairo');
                                    $altUntil = \Carbon\Carbon::parse($altFlyer->valid_until, 'Africa/Cairo');
                                @endphp
                                <article class="group flex flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition hover:shadow-md">
                                    <a href="{{ route('flyers.show', $altFlyer->slug) }}" class="relative aspect-[3/4] overflow-hidden bg-slate-100">
                                        <img src="{{ $altCoverUrl }}" alt="{{ $altFlyer->title }}" width="400" height="533" @if ($loop->first) loading="eager" fetchpriority="high" decoding="async" @else loading="lazy" decoding="async" @endif class="h-full w-full object-cover transition duration-300 group-hover:scale-105">
                                        <span class="absolute right-2 top-2 rounded-lg bg-[#023b55]/90 px-2 py-1 text-[10px] font-bold text-white">{{ $altFlyer->retailer->name }}</span>
                                        <span class="absolute left-2 top-2 rounded-lg bg-slate-900/80 px-2 py-1 text-[11px] font-bold text-white backdrop-blur">{{ \App\Support\ArabicDateHelper::pluralizeCount((int) ($altFlyer->pages_count ?? $altFlyer->total_pages), 'صفحة', 'صفحتان', 'صفحات', 'صفحة') }}</span>
                                    </a>
                                    <div class="flex flex-1 flex-col p-4">
                                        <h3 class="line-clamp-2 text-sm font-bold text-[#023b55] group-hover:text-[#039652]">
                                            <a href="{{ route('flyers.show', $altFlyer->slug) }}">{{ $altFlyer->title }}</a>
                                        </h3>
                                        <div class="mt-3 flex items-center justify-between border-t border-slate-100 pt-3 text-[11px]">
                                            <span class="text-slate-500">{{ $altFrom->format('d/m') }} → {{ $altUntil->format('d/m/Y') }}</span>
                                            <span class="rounded bg-[#039652]/10 px-2 py-0.5 font-bold text-[#027a42]">سارٍ الآن</span>
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

    <!-- Top Active Deals (item-level price highlights) - GEO for AI crawlers -->
    {{-- null-safe: the controller may omit $topRetailerItems (e.g. a retailer
    with no active flyers) — an undefined-variable Blade error here would 500
    the whole hub page. --}}
    @if (($topRetailerItems ?? collect())->isNotEmpty())
        <section class="mb-12">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-lg font-black text-[#023b55] sm:text-xl">أبرز أسعار وعروض {{ $retailer->clean_name }} السارية اليوم</h2>
                <span class="text-xs font-semibold text-slate-500">أسعار فعلية من المجلات السارية</span>
            </div>
            <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
                <table class="w-full min-w-[760px] text-right text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-xs text-slate-600">
                            <th scope="col" class="px-4 py-3 font-black">اسم السلعة</th>
                            <th scope="col" class="px-4 py-3 font-black">الوحدة</th>
                            <th scope="col" class="px-4 py-3 font-black">سعر العرض</th>
                            <th scope="col" class="px-4 py-3 font-black">السعر القديم</th>
                            <th scope="col" class="px-4 py-3 font-black">نسبة التوفير</th>
                            <th scope="col" class="px-4 py-3 font-black">المجلة / العرض</th>
                            <th scope="col" class="px-4 py-3 font-black">تاريخ الانتهاء</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($topRetailerItems as $item)
                            @php
                                // null-safe: a misshapen item must never 500 the hub.
                                $dealFlyer = $item->flyer;
                                $dealUntil = $dealFlyer?->valid_until ? \Carbon\Carbon::parse($dealFlyer->valid_until, 'Africa/Cairo') : null;
                            @endphp
                            <tr class="transition hover:bg-[#039652]/5">
                                <td class="px-4 py-3 font-bold text-[#023b55]">
                                    <a href="{{ route('flyers.show', $item->flyer->slug) }}#item-{{ $item->id }}" class="hover:text-[#039652] hover:underline">
                                        {{ $item->product_name }}@if ($item->brand) <span class="font-normal text-slate-500">— {{ $item->brand->name }}</span>@endif
                                    </a>
                                </td>
                                <td class="px-4 py-3 text-slate-600">{{ $item->unit ?? '—' }}</td>
                                <td class="px-4 py-3 font-black text-[#023b55]">{{ number_format((float) $item->sale_price, 2) }} ج.م</td>
                                <td class="px-4 py-3 text-slate-600">{{ $item->old_price !== null ? number_format((float) $item->old_price, 2).' ج.م' : '—' }}</td>
                                <td class="px-4 py-3">
                                    @if ($item->discount_percent !== null)
                                        <span class="rounded bg-[#039652]/10 px-2 py-0.5 text-xs font-black text-[#027a42]">-{{ round((float) $item->discount_percent) }}%</span>
                                    @else
                                        <span class="text-slate-500">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-xs text-slate-600">
                                    @if ($dealFlyer)
                                        <a href="{{ route('flyers.show', $dealFlyer->slug) }}" class="font-bold text-[#023b55] hover:text-[#039652] hover:underline">{{ $dealFlyer->title }}</a>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-xs text-slate-600">{{ $dealUntil?->format('d/m/Y') ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <!-- Recently Expired (Price History) - Navy -->
    @if ($expiredFlyers->isNotEmpty())
        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-lg font-black text-[#023b55]">مجلات منتهية حديثاً — أرشيف أسعار {{ $retailer->name }} (آخر 30 يوم)</h2>
                <span class="text-xs font-semibold text-slate-500">{{ \App\Support\ArabicDateHelper::pluralizeCount($expiredFlyers->count(), 'مجلة', 'مجلتان', 'مجلات', 'مجلة') }} للسجل السعري</span>
            </div>
            <p class="mb-4 text-xs text-slate-500">للمقارنة ومعرفة تاريخ الأسعار قبل الشراء.</p>
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                @foreach ($expiredFlyers as $flyer)
                    @php
                        $coverPage = $flyer->coverPage;
                        $coverUrl = R2Url::cardThumb($coverPage);
                    @endphp
                    <article class="flex flex-col overflow-hidden rounded-xl border border-slate-200 bg-slate-50 opacity-90">
                        <a href="{{ route('flyers.show', $flyer->slug) }}" rel="nofollow" class="relative aspect-[3/4] overflow-hidden bg-slate-100">
                            <img src="{{ $coverUrl }}" alt="{{ $flyer->title }}" width="400" height="533" loading="lazy" class="h-full w-full object-cover grayscale">
                            <span class="absolute inset-0 bg-slate-900/10"></span>
                            <span class="absolute right-2 top-2 rounded-lg bg-slate-700 px-2 py-1 text-[11px] font-bold text-white">منتهي {{ \Carbon\Carbon::parse($flyer->valid_until)->format('d/m') }}</span>                        </a>
                        <div class="p-3">
                            <h3 class="line-clamp-2 text-xs font-bold text-slate-700">
                                <a href="{{ route('flyers.show', $flyer->slug) }}" rel="nofollow">{{ $flyer->title }}</a>
                            </h3>
                        </div>
                    </article>
                @endforeach
            </div>
        </section>
    @endif

    <!-- Permanent Store Information (anti thin-content) + FAQ -->
    @if (trim((string) $retailer->about_text) !== '' || $faqsList !== [])
        <section class="mt-12 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
            @if (trim((string) $retailer->about_text) !== '')
                <h2 class="text-lg font-black text-[#023b55] sm:text-xl">نبذة عن {{ $retailer->name }}</h2>
                <div class="prose-sm mt-3 max-w-none text-sm leading-relaxed text-slate-700 [&_ol_li]:list-decimal [&_ol]:list-decimal [&_ul_li]:list-disc [&_ul]:list-disc [&_ul]:ps-5">
                    {!! $retailer->about_text !!}
                </div>
            @else
                {{-- Smart fallback: an empty about_text must NEVER leave a
                thin hub — this E-E-A-T card fills the gap right above the FAQ
                (no empty whitespace between the archive and the FAQ). --}}
                <div class="mb-10 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-black text-[#023b55] mb-2">عن عروض وفروع {{ $retailer->clean_name }} في مصر</h2>
                    <p class="text-sm leading-relaxed text-slate-600">
                        تعتبر سلسلة {{ $retailer->name }} من كبرى سلاسل التجزئة في جمهورية مصر العربية{{ $retailer->branches_count ? " بشبكة فروع تتجاوز {$retailer->branches_count} فرعاً" : "" }}. وتتميز بإصدار مجلات وتخفيضات دورية تغطي السلع الغذائية الأساسية والمنظفات والأجهزة المنزلية. نوثق في «مجلاتنا» كل مجلة فور صدورها رسمياً مع تفريغ آلي لأسعار وسعر الوحدة لكل سلعة لتسهيل المقارنة والتوفير الذكي.
                    </p>
                </div>
            @endif

            @if ($faqsList !== [])
                <h2 class="mt-8 text-lg font-black text-[#023b55] sm:text-xl">الأسئلة الشائعة عن عروض {{ $retailer->clean_name }}</h2>
                <div class="mt-4 space-y-5">
                    @foreach ($faqsList as $faq)
                        <div class="rounded-xl border border-slate-200 bg-slate-50/60 p-4">
                            <h3 class="text-sm font-black text-[#023b55]">{{ $faq['question'] }}</h3>
                            <p class="mt-2 text-sm leading-relaxed text-slate-700">{{ $faq['answer'] }}</p>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>
    @endif
</x-layouts.app>
