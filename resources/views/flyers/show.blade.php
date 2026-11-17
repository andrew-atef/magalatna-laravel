@php
    use App\Support\ArabicDateHelper;
    use App\Support\ProductDataNormalizer;
    use App\Support\R2Url;
    $r2DiskUrl = R2Url::base();
    $pages = $flyer->pages->sortBy('page_number')->values();
    $firstPage = $pages->first();
    $coverImage = $firstPage ? R2Url::asset($firstPage->image_path) : url('/img/og-cover.png');
    // Canonical strictly from config('app.url') + relative route — request
    // hosts (admin, CLI, proxies, www) must never poison canonical/@ids.
    $baseUrl = rtrim((string) config('app.url'), '/');
    $pageUrl = $baseUrl.route('flyers.show', ['slug' => $flyer->slug], false);
    $homeUrl = $baseUrl.'/';
    $retailerUrl = $baseUrl.route('retailers.show', ['retailer' => $flyer->retailer->slug], false);
    $cleanTitle = $flyer->title;
    $isExpired = $flyer->isExpired();

    // BLUF — Bulletproof Arabic via Flyer accessor
    $bluf = $flyer->bluf_summary;

    // ── CTR Title (<= 60 chars BEFORE the site suffix) ──
    // Active flyers target "عروض {متجر} {تواريخ}"; expired ones are prefixed
    // "[أرشيف]" so they can never cannibalize the live flyer in SERPs.
    $retailerClean = $flyer->retailer->clean_name;
    // HIGH-CTR TITLE: parenthesized ENGLISH brand names "(Kazyon)" burn ~9
    // characters of the 60-char budget and Google renders them as noise in
    // Arabic SERPs — strip any parenthesized group containing Latin letters
    // (Arabic-only parentheticals like "(انتهى السريان بتاريخ ...)" survive).
    $retailerClean = trim((string) preg_replace('/\s*\([^()]*[A-Za-z][^()]*\)/u', '', $retailerClean));
    $fromDate = \Carbon\Carbon::parse($flyer->valid_from, 'Africa/Cairo');
    $untilDate = \Carbon\Carbon::parse($flyer->valid_until, 'Africa/Cairo');
    // Deterministic Arabic month/day names via ArabicDateHelper —
    // locale-dependent isoFormat('MMMM'/'dddd') silently falls back to
    // ENGLISH names on hosts without the full ICU/intl data pack.
    $datesAr = $fromDate->isSameDay($untilDate)
        ? $untilDate->day.' '.ArabicDateHelper::monthName((int) $untilDate->month)
        : ($fromDate->format('n') === $untilDate->format('n')
            ? $fromDate->format('j').'–'.$untilDate->day.' '.ArabicDateHelper::monthName((int) $untilDate->month)
            : $fromDate->format('j').' '.ArabicDateHelper::monthName((int) $fromDate->month)
                .'–'.$untilDate->format('j').' '.ArabicDateHelper::monthName((int) $untilDate->month));
    $dayNameAr = ArabicDateHelper::dayName($fromDate);
    $untilDateAr = $untilDate->day.' '.ArabicDateHelper::monthName((int) $untilDate->month);

    // Title CANDIDATES (long → short): the layout component APPENDS the
    // " | مجلاتنا" site suffix to $metaTitle, so candidates carry only the
    // page-specific part — pick the first whose FULL title (candidate +
    // suffix) lands inside the 50–60 char Google-pixel-safe window;
    // otherwise word-cut the last one WITHOUT an ellipsis.
    $siteSuffix = ' | '.(string) config('app.name', 'مجلاتنا');
    $titleCandidates = $isExpired
        ? [
            '[أرشيف] عروض '.$retailerClean.' '.$datesAr.' | أرشيف الأسعار',
            '[أرشيف] عروض '.$retailerClean.' '.$datesAr,
        ]
        : ($fromDate->isSameDay($untilDate)
            ? [
                'عروض '.$retailerClean.' '.$dayNameAr.' '.$untilDateAr.' | عرض اليوم الواحد',
                'عروض '.$retailerClean.' '.$dayNameAr.' '.$untilDateAr,
                'عروض '.$retailerClean.' '.$untilDateAr,
            ]
            : [
                'عروض '.$retailerClean.' '.$datesAr.' | مجلة العروض والتوفير',
                'عروض '.$retailerClean.' '.$datesAr,
            ]);
    $seoTitle = '';
    foreach ($titleCandidates as $titleCandidate) {
        $fullLength = mb_strlen($titleCandidate) + mb_strlen($siteSuffix);
        if ($fullLength <= 60 && $fullLength >= 50) {
            $seoTitle = $titleCandidate;
            break;
        }
    }
    if ($seoTitle === '') {
        // Word-safe cut at the last space BEFORE the 60-char FULL-title
        // budget — NO trailing ellipsis (Google truncates at its own pixel
        // limit anyway).
        $fallbackTitle = end($titleCandidates);
        $budget = 60 - mb_strlen($siteSuffix);
        if (mb_strlen($fallbackTitle) > $budget) {
            $cut = mb_substr($fallbackTitle, 0, $budget);
            $space = mb_strrpos($cut, ' ');
            $fallbackTitle = $space !== false && $space > 30 ? mb_substr($cut, 0, $space) : $cut;
        }
        $seoTitle = $fallbackTitle;
    }

    // ── Meta description: REAL item prices from the flyer (135–155 chars,
    // word-safe, no ellipsis) — the snippet IS the answer in SERPs. ──
    $metaDescription = $flyer->generateMetaDescription();

    // Quick Jump Bar target: in-page cross-link widgets when present, else
    // fall back to the retailer hub.
    $otherOffersHref = (isset($sameRetailerFlyers) && $sameRetailerFlyers->isNotEmpty())
        || (isset($competitorFlyers) && $competitorFlyers->isNotEmpty())
        ? '#other-offers'
        : route('retailers.show', $flyer->retailer->slug);

    // ── Arabic grammatical pluralization (تمييز العدد): 1 سلعة / سلعتان /
    // 3..10 سلع / 11+ سلعة — naive "X سلعات" reads like machine translation.
    $itemsCountAr = ArabicDateHelper::pluralizeCount($flyer->items->count(), 'سلعة', 'سلعتان', 'سلع', 'سلعة');
    $pagesCountAr = ArabicDateHelper::pluralizeCount($pages->count(), 'صفحة', 'صفحتان', 'صفحات', 'صفحة');

    // Discover card: the 1280x720 16:9 Google Discover composite when it
    // exists (backfilled/generated by the Gatekeeper), else the cover.
    $discoverImage = $flyer->discover_image_path ? R2Url::asset($flyer->discover_image_path) : $coverImage;
    // og:image PIXEL dimensions for every crawler's preview sizing: exact
    // 1280x720 for the composite; the cover fallback reports the REAL page
    // dimensions (never a guessed pair).
    $hasDiscoverHero = trim((string) $flyer->discover_image_path) !== '';
    if ($hasDiscoverHero) {
        $ogImageWidth = 1280;
        $ogImageHeight = 720;
    } else {
        $ogImageWidth = (int) ($firstPage?->width ?: 1200);
        $ogImageHeight = (int) ($firstPage?->height ?: 1600);
    }

    // ── Present filter buckets: the category bar renders ONLY the buckets
    // that actually exist in THIS flyer — a 3-category flyer shows 3 buttons,
    // never a dead filter (PHOTOS-FIRST UX pass).
    $categoryLabels = [
        'oil' => 'زيوت وسمن',
        'dairy' => 'ألبان وأجبان',
        'meat' => 'لحوم ومجمدات',
        'grocery' => 'بقالة ومشروبات',
        'clean' => 'منظفات وعناية',
    ];
    $presentCategories = $flyer->items
        ->map(fn ($item) => match ($item->category ?: \App\Services\ProductDataNormalizer::inferCategory($item->product_name)) {
            'oils_ghee' => 'oil',
            'dairy_cheese' => 'dairy',
            'meat_poultry' => 'meat',
            'cleaning_laundry', 'personal_care' => 'clean',
            default => 'grocery',
        })
        ->unique()
        ->values()
        ->all();
@endphp

<x-layouts.app
    :meta-title="$seoTitle"
    :meta-description="$metaDescription"
    :og-title="$seoTitle"
    :og-description="$metaDescription"
    :og-image="$discoverImage"
    :og-image-width="$ogImageWidth"
    :og-image-height="$ogImageHeight"
    og-type="article"
    :robots="$isExpired ? 'noindex, follow' : 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1'"
>
    @push('schema')
        @php
            $siteName = config('app.name', 'مجلاتنا');
            $organizationId = $homeUrl.'#organization';
            $websiteId = $homeUrl.'#website';
            $breadcrumbId = $pageUrl.'#breadcrumb';
            $webpageId = $pageUrl.'#webpage';
            $eventId = $pageUrl.'#event';
            $itemListId = $pageUrl.'#itemlist';

            // Cairo-accurate Event dates: date-only strings parsed EXPLICITLY
            // in Africa/Cairo (start/end of day, no TZ drift) → full ISO-8601.
            $eventStart = \Carbon\Carbon::createFromFormat('Y-m-d', $flyer->valid_from->toDateString(), 'Africa/Cairo')->startOfDay()->toIso8601String();
            $eventEnd = \Carbon\Carbon::createFromFormat('Y-m-d', $flyer->valid_until->toDateString(), 'Africa/Cairo')->endOfDay()->toIso8601String();

            // Page-id => absolute image URL map (from already-loaded pages: zero extra queries).
            // Lets each Product carry its own page image instead of the shared cover.
            $pageImages = $pages->mapWithKeys(fn ($p) => [$p->id => R2Url::asset($p->image_path)]);

            // ALL discounted items — itemListElement MUST match numberOfItems
            // (a take(50) truncation makes the count a lie for rich results).
            $discountedItems = $flyer->items->where('sale_price', '>', 0)->values();

            // Real social profiles only — NEVER emit an empty "sameAs": []
            // (Rich Results flags an empty array as an invalid property).
            $socialLinks = array_values(array_filter((array) config('app.social_links', [])));

            // Archive descriptions never leak a fake "سارٍ" claim: expired
            // products are explicitly historical price records.
            $untilArabic = \Carbon\Carbon::parse($flyer->valid_until, 'Africa/Cairo')
                ->locale('ar')->isoFormat('D MMMM YYYY');
            if (! preg_match('/[\x{0600}-\x{06FF}]/u', $untilArabic)) {
                $untilArabic = \App\Support\ArabicDateHelper::formatArabicDate(\Carbon\Carbon::parse($flyer->valid_until, 'Africa/Cairo'));
            }

            $organizationSchema = [
                '@type' => 'Organization',
                '@id' => $organizationId,
                'name' => $siteName,
                'url' => $homeUrl,
                // The REAL 140x36 logo — favicon.svg is a square icon and
                // fails Google's Organization logo image guidelines.
                'logo' => [
                    '@type' => 'ImageObject',
                    'url' => $homeUrl.'logo.webp',
                    'width' => 140,
                    'height' => 36,
                ],
                'areaServed' => [
                    '@type' => 'Country',
                    'name' => 'EG',
                ],
            ];
            if ($socialLinks !== []) {
                $organizationSchema['sameAs'] = $socialLinks;
            }

            $collectionPage = [
                '@type' => 'CollectionPage',
                '@id' => $webpageId,
                'name' => $cleanTitle,
                'description' => $metaDescription,
                'url' => $pageUrl,
                'datePublished' => $flyer->created_at?->timezone('Africa/Cairo')->toIso8601String(),
                'dateModified' => $flyer->updated_at?->timezone('Africa/Cairo')->toIso8601String(),
                'isPartOf' => ['@id' => $websiteId],
                'breadcrumb' => ['@id' => $breadcrumbId],
                'publisher' => ['@id' => $organizationId],
                // 1280x720 Google Discover composite (16:9) — the exact canvas
                // Discover renders, never a letterboxed center-crop.
                'image' => [$discoverImage],
                // The priced ItemList IS the page's main entity.
                'mainEntity' => ['@id' => $itemListId],
            ];

            $graph = [
                $organizationSchema,
                [
                    '@type' => 'WebSite',
                    '@id' => $websiteId,
                    'url' => $homeUrl,
                    'name' => $siteName,
                    'publisher' => ['@id' => $organizationId],
                ],
                [
                    '@type' => 'BreadcrumbList',
                    '@id' => $breadcrumbId,
                    'itemListElement' => [
                        ['@type' => 'ListItem', 'position' => 1, 'name' => 'الرئيسية', 'item' => $homeUrl],
                        ['@type' => 'ListItem', 'position' => 2, 'name' => $flyer->retailer->name, 'item' => $retailerUrl],
                        ['@type' => 'ListItem', 'position' => 3, 'name' => $cleanTitle, 'item' => $pageUrl],
                    ],
                ],
                $collectionPage,
                // EXPIRED flyers: OMIT the SaleEvent entirely — "EventCompleted"
                // is not a valid schema.org enum, and a dead sale must never
                // look like a live event to answer engines.
                ...(! $isExpired ? [[
                    '@type' => 'SaleEvent',
                    '@id' => $eventId,
                    'name' => $cleanTitle,
                    'description' => $bluf,
                    // 1280x720 Discover composite — covers the optional
                    // "image" notice in Google Rich Results for Event data
                    // AND matches the Discover 16:9 canvas.
                    'image' => [$discoverImage],
                    'startDate' => $eventStart,
                    'endDate' => $eventEnd,
                    // Full schema.org URI — bare "EventScheduled" is not valid.
                    'eventStatus' => 'https://schema.org/EventScheduled',
                    'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
                    'location' => [
                        '@type' => 'Place',
                        'name' => 'فروع ' . $flyer->retailer->name . ' بمصر',
                        'address' => [
                            '@type' => 'PostalAddress',
                            'addressCountry' => 'EG',
                        ],
                    ],
                    // The RETAILER runs the sale — not magalatna.com (the
                    // aggregator merely documents it).
                    'organizer' => [
                        '@type' => 'Organization',
                        'name' => $flyer->retailer->name,
                        'url' => $retailerUrl,
                    ],
                ]] : []),
                [
                    '@type' => 'ItemList',
                    '@id' => $itemListId,
                    'name' => 'قائمة أسعار وسلع ' . $cleanTitle,
                    'numberOfItems' => $discountedItems->count(),
                    'itemListElement' => $discountedItems->map(function ($item, $idx) use ($pageUrl, $flyer, $coverImage, $pageImages, $isExpired, $eventStart, $untilArabic) {
                        $hasOld = $item->old_price && (float) $item->old_price > (float) $item->sale_price;
                        $offer = [
                            '@type' => 'Offer',
                            'url' => $pageUrl . '#item-' . $item->id,
                            'price' => number_format((float) $item->sale_price, 2, '.', ''),
                            'priceCurrency' => 'EGP',
                            // Expired archive rows are historical price records,
                            // NOT purchasable offers: "Discontinued" is the
                            // accurate availability state for archived SKUs.
                            'availability' => $isExpired ? 'https://schema.org/Discontinued' : 'https://schema.org/InStock',
                            'seller' => ['@type' => 'Organization', 'name' => $flyer->retailer->name],
                        ];
                        if (! $isExpired) {
                            // Freshness dates only make sense on a LIVE offer:
                            // an archive entry must not advertise validity windows.
                            $offer['validFrom'] = $eventStart;
                            $offer['priceValidUntil'] = \Carbon\Carbon::parse($flyer->valid_until)->format('Y-m-d');
                        }
                        if ($hasOld) {
                            $offer['priceSpecification'] = [
                                '@type' => 'UnitPriceSpecification',
                                // The struck-through old price is a strikethrough,
                                // NOT a ListPrice (ListPrice claims a real RRP).
                                'priceType' => 'https://schema.org/StrikethroughPrice',
                                'price' => number_format((float) $item->old_price, 2, '.', ''),
                                'priceCurrency' => 'EGP',
                            ];
                        }
                        $product = [
                            '@type' => 'Product',
                            'name' => $item->product_name,
                            // Real-data description: LIVE = current offer; EXPIRED
                            // = honest historical archive note (never "سارٍ").
                            'description' => $isExpired
                                ? $item->product_name . ' — أرشيف سعر سابق في ' . $flyer->retailer->name . ' (انتهى في ' . $untilArabic . ')'
                                : $item->product_name . ' - عرض سارٍ في ' . $flyer->retailer->name . ($item->unit ? ' (' . $item->unit . ')' : ''),
                            'image' => $pageImages[$item->flyer_page_id] ?? $coverImage,
                            'url' => $pageUrl . '#item-' . $item->id,
                            'offers' => $offer,
                        ];
                        // Brand hygiene: ONLY a real brand — NEVER fall back to
                        // the product name (a Product is not its own Brand).
                        if ($item->brand?->name) {
                            $product['brand'] = ['@type' => 'Brand', 'name' => $item->brand->name];
                        }
                        return [
                            '@type' => 'ListItem',
                            'position' => $idx + 1,
                            'item' => $product,
                        ];
                    })->all(),
                ],
            ];

            $unifiedSchema = [
                '@context' => 'https://schema.org',
                '@graph' => $graph,
            ];
        @endphp
        <script type="application/ld+json">{!! json_encode($unifiedSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) !!}</script>
    @endpush

    <!-- Breadcrumb - Navy hover -->
    <nav class="mb-4 text-xs font-semibold text-slate-500" aria-label="Breadcrumb">
        <ol class="flex items-center gap-1.5">
            <li><a href="{{ $homeUrl }}" class="hover:text-[#039652]">الرئيسية</a></li>
            <li>/</li>
            <li><a href="{{ route('retailers.show', $flyer->retailer->slug) }}" class="hover:text-[#039652]">{{ $flyer->retailer->name }}</a></li>
            <li>/</li>
            <li class="text-[#023b55] truncate max-w-xs font-bold">{{ $cleanTitle }}</li>
        </ol>
    </nav>

    <!-- Flyer Header Card - Navy headings, Green validity -->
    <article class="mb-6 rounded-2xl border border-[#e2e8f0] bg-white p-6 shadow-sm">
        @if($isExpired)
            <div class="mb-6 rounded-2xl border-2 border-amber-500/30 bg-amber-50/80 p-5 text-amber-950 shadow-sm">
                <div class="flex items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-amber-500 text-white font-black text-lg">⚠️</span>
                    <div class="flex-1">
                        <h2 class="text-base font-black text-amber-900">تنبيه: هذا العرض انتهت فترة سريانه في {{ \Carbon\Carbon::parse($flyer->valid_until)->format('Y/m/d') }}</h2>
                        <p class="mt-0.5 text-xs text-amber-800">
                            الأسعار والخصومات الواردة أدناه محفوظة كأرشيف تاريخي لمقارنة تطور الأسعار في مصر.
                        </p>
                        @if($latestActiveFlyer ?? null)
                            <div class="mt-3 flex flex-wrap items-center justify-between gap-2 rounded-xl bg-white p-3 border border-amber-200">
                                <span class="text-xs font-bold text-slate-700">تتوفر الآن مجلة جديدة سارية لهذا المتجر:</span>
                                <a href="{{ route('flyers.show', $latestActiveFlyer->slug) }}" class="inline-flex items-center gap-1 rounded-lg bg-[#039652] px-3.5 py-1.5 text-xs font-black text-white hover:bg-[#023b55] transition shadow-sm">
                                    مشاهدة عروض {{ $flyer->retailer->name }} السارية الآن ←
                                </a>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        @endif
        <div class="flex flex-wrap items-center justify-between gap-4 border-b border-slate-100 pb-4">
            <div>
                <span class="inline-block rounded-lg bg-[#023b55]/10 px-2.5 py-1 text-xs font-bold text-[#023b55]">
                    {{ $flyer->retailer->name }}
                </span>
                <h1 class="mt-2 text-2xl font-black text-[#023b55] sm:text-3xl">{{ $cleanTitle }}</h1>
            </div>

            <div class="flex items-center gap-3">
                @if ($isExpired)
                    <span class="rounded-xl border border-slate-200 bg-slate-100 px-3 py-1.5 text-xs font-bold text-slate-600">
                        انتهى في {{ $untilDate->format('d/m/Y') }}
                    </span>
                @elseif ($fromDate->isFuture())
                    <span class="rounded-xl border border-[#fcc023]/50 bg-[#fcc023] px-3 py-1.5 text-xs font-bold text-slate-900">
                        يبدأ قريباً {{ $fromDate->format('d/m/Y') }}
                    </span>
                    <span class="rounded-xl border border-[#023b55]/15 bg-white px-3 py-1.5 text-xs font-bold text-[#023b55]">
                        سارٍ من {{ $fromDate->format('d/m/Y') }} حتى {{ $untilDate->format('d/m/Y') }}
                    </span>
                @else
                    <span class="rounded-xl border border-[#039652]/30 bg-[#039652]/10 px-3 py-1.5 text-xs font-bold text-[#027a42]">
                        سارٍ حتى {{ $untilDate->format('d/m/Y') }}
                    </span>
                @endif
            </div>
        </div>

        <!-- 2-Sentence BLUF Summary Box - GEO chunk with right accent -->
        @php
            $blufHtml = e($bluf);
            // Bold critical entities for LLM RAG
            $storeName = e($flyer->retailer->name);
            $blufHtml = str_replace($storeName, '<strong>' . $storeName . '</strong>', $blufHtml);
            // Bold total pages
            $pagesText = (string) $flyer->total_pages . ' صفحة';
            $pagesTextAlt = (string) $flyer->total_pages . ' صفحات';
            $blufHtml = str_replace($pagesText, '<strong>' . $pagesText . '</strong>', $blufHtml);
            $blufHtml = str_replace($pagesTextAlt, '<strong>' . $pagesTextAlt . '</strong>', $blufHtml);
            // Bold discount percentages like 42% or 42.39%
            $blufHtml = (string) preg_replace('/(\d+(?:\.\d+)?%)/u', '<strong>$1</strong>', $blufHtml);
            // Bold date spans (dd/mm/yyyy or Arabic dates)
            $blufHtml = (string) preg_replace('/\d{1,2}\/\d{1,2}\/\d{4}/u', '<strong>$0</strong>', $blufHtml);
            // Bold prices like 14.95 ج.م
            $blufHtml = (string) preg_replace('/\d+(?:\.\d+)?\s*ج\.م/u', '<strong>$0</strong>', $blufHtml);
        @endphp
        <div class="mt-4 rounded-xl border border-slate-200 border-r-4 border-r-[#039652] bg-emerald-50/40 p-4 text-sm font-medium leading-relaxed text-slate-700">
            <p class="font-bold text-[#023b55] mb-1">📌 تفاصيل وسريان العرض:</p>
            <p>{!! $blufHtml !!}</p>
        </div>
    </article>

    {{-- PHOTOS FIRST (mobile UX + Google Discover): the flyer pages gallery
    comes IMMEDIATELY after the header — users tap into the magazine they came
    for before scrolling into any prose or table. --}}
    <!-- عارض المجلة مصورة (Flyer Viewer) - Page 1 featured + compact 2-col grid -->
    <section id="flyer-viewer" class="mb-10 scroll-mt-20">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-lg font-black text-[#023b55]">تصفح صفحات المجلة ({{ $pagesCountAr }})</h2>
            <span class="hidden text-xs text-slate-500 sm:inline">اضغط على أي صفحة للتكبير والتنقل</span>
        </div>

        {{-- Discover 1280x720 hero card: the elegant pre-cover band — the
        SAME composite that og:image advertises, so the feed card and the
        page agree pixel-perfectly. It takes the LCP role (eager +
        fetchpriority=high) and page 1 drops to lazy below it. --}}
        @if ($hasDiscoverHero)
            <div class="mx-auto mb-6 max-w-3xl overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <img
                    src="{{ $discoverImage }}"
                    alt="صورة غلاف مجلة {{ $cleanTitle }} بأسعارها المميزة على خلفية كازيون"
                    width="1280" height="720"
                    loading="eager" fetchpriority="high" decoding="async"
                    class="w-full h-auto"
                >
            </div>
        @endif

        {{-- Page 1 (cover) featured full-width: eager + fetchpriority=high —
        it IS the LCP element. A4 vertical pages need full width for legibility. --}}
        @if ($firstPage)
            @php
                $fullImageUrl = R2Url::asset($firstPage->image_path);
                $mobileOptimizedUrl = R2Url::resizedAsset($firstPage->image_path, 600);
                $pageW = (int) ($firstPage->width ?: 1200);
                $pageH = (int) ($firstPage->height ?: 1600);
            @endphp
            <div class="mx-auto max-w-3xl overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-100 bg-slate-50 px-4 py-2 text-xs font-bold text-slate-600">
                    صفحة رقم {{ $firstPage->page_number }}
                </div>
                <button type="button" data-index="0" aria-label="تكبير صفحة {{ $firstPage->page_number }}" class="lightbox-trigger block w-full cursor-zoom-in focus:outline-none focus:ring-2 focus:ring-[#039652] focus:ring-offset-2" style="aspect-ratio: {{ $pageW }}/{{ $pageH }};">
                    <img
                        src="{{ $fullImageUrl }}"
                        @if ($mobileOptimizedUrl && $mobileOptimizedUrl !== $fullImageUrl)
                            srcset="{{ $fullImageUrl }} {{ $pageW }}w, {{ $mobileOptimizedUrl }} 600w"
                            sizes="(max-width: 640px) 100vw, 768px"
                        @endif
                        alt="{{ $cleanTitle }} - صفحة {{ $firstPage->page_number }}"
                        width="{{ $pageW }}"
                        height="{{ $pageH }}"
                        @if ($hasDiscoverHero) loading="lazy" @else loading="eager" fetchpriority="high" @endif decoding="async"
                        class="w-full h-auto object-contain"
                    >
                </button>
            </div>
        @endif

        {{-- Pages 2..N: ONE per row on mobile (A4 pages need full width to
        be legible on a phone), compact 2-column grid from sm: up — cuts
        ~12,000px of vertical scroll on 25-page flyers while EVERY <img>
        stays crawlable and opens the Lightbox on click. --}}
        @if ($pages->count() > 1)
            <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 sm:gap-4">
                @foreach ($pages->slice(1) as $page)
                    @php
                        $pageIdx = $loop->iteration; // offset of featured page 1
                        $fullImageUrl = R2Url::asset($page->image_path);
                        $mobileOptimizedUrl = R2Url::resizedAsset($page->image_path, 600);
                        $pageW = (int) ($page->width ?: 1200);
                        $pageH = (int) ($page->height ?: 1600);
                    @endphp
                    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                        <div class="border-b border-slate-100 bg-slate-50 px-3 py-1.5 text-[11px] font-bold text-slate-600">
                            صفحة رقم {{ $page->page_number }}
                        </div>
                        <button type="button" data-index="{{ $pageIdx }}" aria-label="تكبير صفحة {{ $page->page_number }}" class="lightbox-trigger block w-full cursor-zoom-in focus:outline-none focus:ring-2 focus:ring-[#039652] focus:ring-offset-2" style="aspect-ratio: {{ $pageW }}/{{ $pageH }};">
                            <img
                                src="{{ $fullImageUrl }}"
                                @if ($mobileOptimizedUrl && $mobileOptimizedUrl !== $fullImageUrl)
                                    srcset="{{ $fullImageUrl }} {{ $pageW }}w, {{ $mobileOptimizedUrl }} 600w"
                                    sizes="(max-width: 640px) 100vw, 46vw"
                                @endif
                                alt="{{ $cleanTitle }} - صفحة {{ $page->page_number }}"
                                width="{{ $pageW }}"
                                height="{{ $pageH }}"
                                loading="lazy" decoding="async"
                                class="w-full h-auto object-contain"
                            >
                        </button>
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    <!-- Quick Jump Bar (below the photos): prices & other offers ONE tap away -->
    <nav class="mb-8 flex flex-wrap items-center gap-2" aria-label="التنقل السريع داخل الصفحة">
        <a href="#items-table" class="inline-flex items-center gap-1 rounded-full border border-[#039652]/25 bg-[#039652]/10 px-4 py-2 text-xs font-black text-[#027a42] hover:bg-[#039652] hover:text-white transition">
            📊 جدول الأسعار والسلع المفصلة ({{ $itemsCountAr }}) ↓
        </a>
        <a href="{{ $otherOffersHref }}" class="inline-flex items-center gap-1 rounded-full border border-slate-200 bg-white px-4 py-2 text-xs font-black text-[#023b55] hover:border-[#039652]/40 hover:text-[#039652] transition">
            🏪 عروض ومجلات أخرى ↓
        </a>
    </nav>

    <!-- Editorial Overview — between the photos and the price table: the
    photos stay first for users, the editorial text chunk feeds crawlers/AI. -->
    @if (! $isExpired && !empty($flyer->editorial_overview))
    <section class="mb-10 rounded-2xl border border-[#023b55]/10 bg-white p-5 sm:p-6 shadow-sm">
        <h2 class="mb-3 text-lg font-black text-[#023b55]">نظرة سريعة على العرض</h2>
        @php
            $cleanEditorial = trim((string) $flyer->editorial_overview);
            $cleanEditorial = (string) preg_replace("/[ \t]+\n/u", "\n", $cleanEditorial);
            $cleanEditorial = (string) preg_replace("/\n{3,}/u", "\n\n", $cleanEditorial);
            $cleanEditorial = (string) preg_replace("/:\n\n-/u", ":\n- ", $cleanEditorial);
        @endphp
        <div class="max-w-none text-[13px] leading-6 text-slate-700 prose prose-sm prose-headings:text-[#023b55] prose-strong:text-[#023b55] prose-a:text-[#039652] prose-a:font-bold">
            {!! app(\App\Services\AutoInternalLinkerService::class)->linkifyEditorial($cleanEditorial, $flyer) !!}
        </div>
    </section>
    @endif

    <!-- جدول الأسعار والسلع (Structured Items Table) — BELOW the photos per
    the PHOTOS-FIRST UX pass; the jump bar above deep-links here. -->
    <section id="items-table" class="mb-12 scroll-mt-20 rounded-2xl border border-[#e2e8f0] bg-white p-6 shadow-sm">
        <h2 class="mb-2 text-lg font-black text-[#023b55]">جدول السلع والأسعار المفصلة في هذا العرض</h2>
        <p class="mb-4 text-xs text-slate-500 flex flex-wrap items-center gap-2">
            <span>تم استخراج وتدقيق هذه الأسعار تلقائياً عبر الذكاء الاصطناعي.</span>
            <span class="inline-flex items-center gap-1 rounded-md bg-[#039652]/10 px-2 py-0.5 font-bold text-[#027a42]">
                🕒 آخر رصد وتحديث للأسعار: {{ $flyer->updated_at?->timezone('Africa/Cairo')->format('Y/m/d h:i A') }} ({{ $flyer->updated_at?->timezone('Africa/Cairo')->diffForHumans() }})
            </span>
        </p>

        <!-- Instant client-side filter bar (zero dependencies, < 1.5KB JS) -->
        {{-- Only the buckets that ACTUALLY exist in this flyer are rendered:
        a 3-category flyer shows 3 buttons, never a dead filter. --}}
        <div class="mb-4 flex flex-col gap-3">
            <div id="items-filterbar" class="flex flex-wrap gap-2" role="group" aria-label="تصفية الجدول حسب التصنيف">
                <button type="button" data-filter="all" class="items-filter-btn is-active inline-flex items-center rounded-full border border-slate-200 bg-white px-3.5 py-1.5 text-xs font-black text-[#023b55] transition cursor-pointer hover:border-[#039652]/40">الكل</button>
                @foreach ($categoryLabels as $bucketKey => $bucketLabel)
                    @if (in_array($bucketKey, $presentCategories, true))
                        <button type="button" data-filter="{{ $bucketKey }}" class="items-filter-btn inline-flex items-center rounded-full border border-slate-200 bg-white px-3.5 py-1.5 text-xs font-black text-[#023b55] transition cursor-pointer hover:border-[#039652]/40">{{ $bucketLabel }}</button>
                    @endif
                @endforeach
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <input id="items-search" type="search" placeholder="ابحث في الجدول (اسم السلعة أو الماركة)..." aria-label="بحث فوري داخل جدول الأسعار" class="w-full max-w-xs rounded-xl border border-slate-200 bg-slate-50 px-4 py-2 text-xs font-medium text-[#023b55] placeholder:text-slate-400 focus:border-[#039652] focus:bg-white focus:outline-none focus:ring-2 focus:ring-[#039652]/20">
                <span id="items-count" class="text-xs font-bold text-slate-500">{{ $itemsCountAr }}</span>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-right text-xs">
                <thead class="border-b border-[#023b55]/10 bg-[#023b55]/5 text-[#023b55]">
                    <tr>
                        <th class="p-3 font-bold">اسم السلعة</th>
                        <th class="p-3 font-bold">الماركة</th>
                        <th class="p-3 font-bold">الوحدة</th>
                        <th class="p-3 font-bold">سعر الوحدة</th>
                        <th class="p-3 font-bold">سعر العرض</th>
                        <th class="p-3 font-bold">السعر القديم</th>
                        <th class="p-3 font-bold">نسبة التوفير</th>
                    </tr>
                </thead>
                <tbody id="items-table-body" class="divide-y divide-slate-100 font-medium text-slate-800">
                    @forelse ($flyer->items as $item)
                        @php
                            // UI filter buckets come from the STORED 10-value
                            // category (falling back to the deterministic
                            // classifier) — NOT classifyProduct(), whose
                            // keyword buckets misfile "بديل زيت" under oil and
                            // motor oil under "oil". automotive_tools has no
                            // UI bucket and folds into grocery.
                            $cat = match ($item->category ?: \App\Services\ProductDataNormalizer::inferCategory($item->product_name)) {
                                'oils_ghee' => 'oil',
                                'dairy_cheese' => 'dairy',
                                'meat_poultry' => 'meat',
                                'cleaning_laundry', 'personal_care' => 'clean',
                                default => 'grocery',
                            };
                            $unitPrice = ProductDataNormalizer::extractStandardUnitPrice($item);
                        @endphp
                        <tr id="item-{{ $item->id }}" data-cat="{{ $cat }}" class="hover:bg-[#039652]/5">
                            <td class="p-3 font-bold text-[#023b55]">
                                <div>{{ $item->product_name }}</div>
                                @php $compareQuery = ProductDataNormalizer::searchQuery($item->product_name, $item->brand?->name); @endphp
                                @if ($compareQuery !== '')
                                    {{-- Interactive <button>, NOT a crawlable <a>: one ?q= search link per
                                    item row flooded GSC with 152 internal-search crawl-bloat URLs. Bots do
                                    not execute onclick, so this emits zero discoverable ?q= URLs. --}}
                                    <button type="button" onclick="window.location.href='{{ route('home', ['q' => $compareQuery]) }}'" class="mt-1 inline-flex items-center gap-1 rounded-full border border-[#039652]/20 bg-[#039652]/10 px-2 py-0.5 text-[10px] font-bold text-[#027a42] hover:bg-[#039652] hover:text-white transition cursor-pointer">قارن الأسعار 🔍</button>
                                @endif
                            </td>
                            <td class="p-3 text-slate-500">{{ $item->brand?->name ?: '—' }}</td>
                            <td class="p-3 text-slate-500">{{ $item->unit ?: 'قطعة' }}</td>
                            <td class="p-3 font-bold text-[#023b55]">
                                @if ($unitPrice)
                                    {{ number_format($unitPrice['amount'], 2) }} ج.م / {{ $unitPrice['unit'] }}
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
                            </td>
                            <td class="p-3 text-sm font-black text-[#039652]">{{ number_format((float) $item->sale_price, 2) }} ج.م</td>
                            <td class="p-3 text-slate-400">
                                @if ($item->old_price)
                                    <span class="line-through">{{ number_format((float) $item->old_price, 2) }} ج.م</span>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="p-3">
                                @if ($item->discount_percent)
                                    @php $isSuper = (float) $item->discount_percent > 30; @endphp
                                    <span class="rounded px-2 py-0.5 text-[11px] font-bold {{ $isSuper ? 'bg-[#fcc023]/20 text-slate-900 border border-[#fcc023]/30' : 'bg-[#039652]/10 text-[#027a42]' }}">
                                        خصم {{ round((float) $item->discount_percent) }}%
                                    </span>
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="p-6 text-center text-slate-400">لا توجد سلع مسجلة لهذه المجلة بعد.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p id="items-empty" class="hidden rounded-xl border border-dashed border-slate-300 bg-slate-50 p-6 text-center text-sm text-slate-500">لا توجد سلع مطابقة لهذه التصفية — جرب تصنيفاً آخر أو كلمة بحث أعم.</p>
    </section>

    <!-- Lightbox Modal - Pure JS, Zero CDN - Navy/Green theme -->
    <div id="flyer-lightbox" class="fixed inset-0 z-50 hidden items-center justify-center bg-[#023b55]/90 p-4 backdrop-blur-sm" role="dialog" aria-modal="true" aria-label="عارض الصفحات">
        <div class="relative flex h-full w-full max-w-5xl flex-col">
            <!-- Top Bar: Page indicator + Controls -->
            <div class="mb-3 flex items-center justify-between text-white">
                <span id="lightbox-indicator" class="rounded-full bg-white/15 px-3 py-1 text-xs font-bold backdrop-blur border border-white/20">صفحة 1 من 1</span>
                <div class="flex items-center gap-2">
                    <button type="button" id="lightbox-zoom" class="rounded-full bg-white/15 p-2.5 text-white backdrop-blur transition hover:bg-[#039652] hover:text-white border border-white/20" aria-label="تكبير">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM10 7v6m3-3H7"/></svg>
                    </button>
                    <button type="button" id="lightbox-close" class="rounded-full bg-white p-2.5 text-[#023b55] shadow transition hover:bg-[#039652] hover:text-white" aria-label="إغلاق">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
            </div>
            <!-- Image Container -->
            <div id="lightbox-stage" class="relative flex flex-1 items-center justify-center overflow-hidden rounded-xl bg-[#023b55]">
                <button type="button" id="lightbox-prev" class="absolute left-2 z-10 rounded-full bg-white/90 p-3 text-[#023b55] shadow-lg transition hover:bg-[#039652] hover:text-white sm:left-4" aria-label="السابق">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                </button>
                <img id="lightbox-image" src="" alt="" class="max-h-[75vh] max-w-full object-contain transition-transform duration-200" style="transform: scale(1);">
                <button type="button" id="lightbox-next" class="absolute right-2 z-10 rounded-full bg-white/90 p-3 text-[#023b55] shadow-lg transition hover:bg-[#039652] hover:text-white sm:right-4" aria-label="التالي">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                </button>
            </div>
            <p class="mt-3 text-center text-xs text-white/70">استخدم ← → للتنقل، +/- للتكبير، Esc للإغلاق</p>
        </div>
    </div>

    <style>
        #flyer-lightbox:not(.hidden){display:flex}#flyer-lightbox.hidden{display:none} html{scroll-behavior:smooth} tr:target, tr.highlight-target{background-color:rgba(3,150,82,0.10)!important; transition:background-color .3s ease}
        .items-filter-btn.is-active{background-color:#039652;border-color:#039652;color:#fff}
    </style>

    <!-- Instant table filter - lightweight, zero dependencies (~1.2KB) -->
    <script>
    (function(){
        var rows = [].slice.call(document.querySelectorAll('#items-table-body tr[data-cat]'));
        if(!rows.length) return;
        var active = 'all';
        var countEl = document.getElementById('items-count');
        var emptyEl = document.getElementById('items-empty');
        function norm(s){ return (s||'').replace(/[أإآ]/g,'ا').replace(/ة/g,'ه').replace(/ى/g,'ي').toLowerCase(); }
        // Mirror of ArabicDateHelper::pluralizeCount() — the live filter count
        // must read grammatical Arabic too (1 سلعة / سلعتان / 3..10 سلع / 11+ سلعة).
        function pluralizeAr(n){
            if(n === 1) return '1 سلعة';
            if(n === 2) return 'سلعتان';
            if(n >= 3 && n <= 10) return n + ' سلع';
            return n + ' سلعة';
        }
        function apply(){
            var q = norm((document.getElementById('items-search')||{}).value || '').trim();
            var shown = 0;
            rows.forEach(function(r){
                var ok = (active === 'all' || r.getAttribute('data-cat') === active)
                    && (!q || norm(r.textContent).indexOf(q) !== -1);
                r.style.display = ok ? '' : 'none';
                if(ok) shown++;
            });
            if(countEl) countEl.textContent = pluralizeAr(shown);
            if(emptyEl) emptyEl.classList.toggle('hidden', shown > 0);
        }
        document.querySelectorAll('#items-filterbar [data-filter]').forEach(function(btn){
            btn.addEventListener('click', function(){
                active = btn.getAttribute('data-filter');
                document.querySelectorAll('#items-filterbar [data-filter]').forEach(function(b){
                    b.classList.remove('is-active');
                });
                btn.classList.add('is-active');
                apply();
            });
        });
        var search = document.getElementById('items-search');
        if(search) search.addEventListener('input', apply);
        // Deep links (#item-123) must be VISIBLE: reset filters once if the
        // hash target is hidden.
        if(location.hash.indexOf('#item-') === 0){
            document.querySelectorAll('#items-filterbar [data-filter="all"]')[0].click();
        }
    })();
    </script>

    <!-- Deep-link highlight for #item-{id} from hot deals -->
    <script>
    document.addEventListener('DOMContentLoaded', function(){
        function highlightHash(){
            const hash = location.hash;
            if(hash && hash.startsWith('#item-')){
                const el = document.querySelector(hash);
                if(el){
                    el.classList.add('highlight-target');
                    el.scrollIntoView({behavior:'smooth', block:'center'});
                    setTimeout(function(){ el.classList.remove('highlight-target'); }, 2000);
                }
            }
        }
        highlightHash();
        window.addEventListener('hashchange', highlightHash);
    });
    </script>
    <script>
    document.addEventListener('DOMContentLoaded', function(){
        const pages = {!! json_encode($pages->map(fn($p) => ['src' => R2Url::asset($p->image_path), 'alt' => $cleanTitle.' - صفحة '.$p->page_number])->all(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '[]' !!};
        if(!pages.length) return;
        let current = 0;
        let zoomed = false;
        const lightbox = document.getElementById('flyer-lightbox');
        const img = document.getElementById('lightbox-image');
        const indicator = document.getElementById('lightbox-indicator');
        const stage = document.getElementById('lightbox-stage');
        const btnClose = document.getElementById('lightbox-close');
        const btnPrev = document.getElementById('lightbox-prev');
        const btnNext = document.getElementById('lightbox-next');
        const btnZoom = document.getElementById('lightbox-zoom');
        const triggers = document.querySelectorAll('.lightbox-trigger');
        if(!lightbox || !img) return;
        function update(){
            const p = pages[current];
            img.src = p.src;
            img.alt = p.alt;
            indicator.textContent = 'صفحة ' + (current + 1) + ' من ' + pages.length;
            btnPrev.style.display = current === 0 ? 'none' : 'block';
            btnNext.style.display = current === pages.length - 1 ? 'none' : 'block';
            setZoom(false);
        }
        function setZoom(on){
            zoomed = on;
            img.style.transform = zoomed ? 'scale(2)' : 'scale(1)';
            img.style.cursor = zoomed ? 'zoom-out' : 'zoom-in';
            stage.style.overflow = zoomed ? 'auto' : 'hidden';
            btnZoom.innerHTML = zoomed
                ? '<svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM13 10H7"/></svg>'
                : '<svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM10 7v6m3-3H7"/></svg>';
        }
        function open(idx){
            current = idx;
            update();
            lightbox.classList.remove('hidden');
            lightbox.classList.add('flex');
            document.body.style.overflow = 'hidden';
            document.addEventListener('keydown', onKey);
        }
        function close(){
            lightbox.classList.add('hidden');
            lightbox.classList.remove('flex');
            document.body.style.overflow = '';
            document.removeEventListener('keydown', onKey);
            setZoom(false);
        }
        function onKey(e){
            if(lightbox.classList.contains('hidden')) return;
            if(e.key === 'Escape') close();
            else if(e.key === 'ArrowLeft') { if(current < pages.length - 1){ current++; update(); } }
            else if(e.key === 'ArrowRight') { if(current > 0){ current--; update(); } }
            else if(e.key === '+' || e.key === '=' ) setZoom(true);
            else if(e.key === '-' || e.key === '_') setZoom(false);
        }
        triggers.forEach(function(btn){
            btn.addEventListener('click', function(){ open(parseInt(btn.getAttribute('data-index'),10)); });
        });
        btnClose.addEventListener('click', close);
        btnPrev.addEventListener('click', function(){ if(current > 0){ current--; update(); }});
        btnNext.addEventListener('click', function(){ if(current < pages.length - 1){ current++; update(); }});
        btnZoom.addEventListener('click', function(){ setZoom(!zoomed); });
        img.addEventListener('click', function(){ setZoom(!zoomed); });
        lightbox.addEventListener('click', function(e){ if(e.target === lightbox) close(); });
    });
    </script>

    <!-- Dynamic Cross-Linking Widgets -->
    <div id="other-offers" class="scroll-mt-20">
    @if (isset($sameRetailerFlyers) && $sameRetailerFlyers->isNotEmpty())
    <section class="rounded-2xl border border-[#023b55]/10 bg-white p-6 shadow-sm">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-lg font-black text-[#023b55]">عروض أخرى سارية اليوم من {{ $flyer->retailer->name }}</h2>
            <a href="{{ route('retailers.show', $flyer->retailer->slug) }}" class="text-xs font-bold text-[#039652] hover:underline">عرض الكل ←</a>
        </div>
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
            @foreach ($sameRetailerFlyers as $related)
                @php $cov = $related->coverPage; $u = R2Url::cardThumb($cov); @endphp
                <a href="{{ route('flyers.show', $related->slug) }}" class="group flex flex-col overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm transition hover:border-[#039652] hover:shadow-md">
                    <div class="relative aspect-[3/4] overflow-hidden bg-slate-100">
                        <img src="{{ $u }}" alt="{{ $related->title }}" width="400" height="533" loading="lazy" class="h-full w-full object-cover transition duration-300 group-hover:scale-[1.03]">
                        <span class="absolute right-2 top-2 rounded-lg bg-[#023b55] px-2 py-1 text-[10px] font-bold text-white">{{ $related->total_pages }} صفحة</span>
                    </div>
                    <div class="p-3">
                        <div class="text-[11px] font-bold text-[#039652]">{{ $related->retailer->name }}</div>
                        <h3 class="mt-1 line-clamp-2 text-xs font-black text-[#023b55] group-hover:text-[#039652]">{{ $related->title }}</h3>
                        <div class="mt-2 text-[11px] font-medium text-slate-500">حتى {{ \Carbon\Carbon::parse($related->valid_until)->format('d/m/Y') }}</div>
                    </div>
                </a>
            @endforeach
        </div>
    </section>
    @endif

    @if (isset($competitorFlyers) && $competitorFlyers->isNotEmpty())
    <section class="mt-6 rounded-2xl border border-[#023b55]/10 bg-white p-6 shadow-sm">
        <div class="mb-4">
            <h2 class="text-lg font-black text-[#023b55]">عروض سلاسل السوبرماركت الأخرى السارية اليوم بمصر</h2>
            <p class="mt-1 text-xs text-slate-500">قارن عروض نفس الفترة من منافسين آخرين</p>
        </div>
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
            @foreach ($competitorFlyers as $comp)
                @php $cov = $comp->coverPage; $u = R2Url::cardThumb($cov); @endphp
                <a href="{{ route('flyers.show', $comp->slug) }}" class="group flex flex-col overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm transition hover:border-[#039652] hover:shadow-md">
                    <div class="relative aspect-[3/4] overflow-hidden bg-slate-100">
                        <img src="{{ $u }}" alt="{{ $comp->title }}" width="400" height="533" loading="lazy" class="h-full w-full object-cover transition duration-300 group-hover:scale-[1.03]">
                        <span class="absolute left-2 top-2 rounded-full bg-white/90 px-2 py-0.5 text-[10px] font-black text-[#023b55] border border-slate-200">{{ $comp->retailer->name }}</span>
                    </div>
                    <div class="p-3">
                        <h3 class="line-clamp-2 text-xs font-black text-[#023b55] group-hover:text-[#039652]">{{ $comp->title }}</h3>
                        <div class="mt-1 text-[11px] text-slate-500">{{ \Carbon\Carbon::parse($comp->valid_from)->format('d/m') }} → {{ \Carbon\Carbon::parse($comp->valid_until)->format('d/m/Y') }}</div>
                    </div>
                </a>
            @endforeach
        </div>
        <div class="mt-4 text-center">
            <a href="{{ $homeUrl }}" class="inline-flex rounded-xl border border-[#023b55]/15 bg-[#023b55]/5 px-4 py-2 text-xs font-bold text-[#023b55] hover:bg-[#039652] hover:text-white hover:border-[#039652] transition">تصفح كل العروض السارية →</a>
        </div>
    </section>
    @endif

    @if (isset($archiveFlyers) && $archiveFlyers->isNotEmpty())
    <section class="mt-6 rounded-2xl border border-[#e2e8f0] bg-slate-50 p-6 shadow-sm">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-lg font-black text-[#023b55]">أرشيف عروض {{ $flyer->retailer->name }} السابقة</h2>
            <span class="rounded-full bg-white border border-slate-200 px-2.5 py-1 text-[11px] font-bold text-slate-600">{{ $archiveFlyers->count() }} مجلة للسجل السعري</span>
        </div>
        <p class="mb-4 text-xs text-slate-500">للمقارنة ومعرفة تطور الأسعار قبل الشراء — توثيق تاريخي لآخر 30 يوم</p>
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
            @foreach ($archiveFlyers as $arc)
                @php $cov = $arc->coverPage; $u = R2Url::cardThumb($cov); @endphp
                <a href="{{ route('flyers.show', $arc->slug) }}" class="flex flex-col overflow-hidden rounded-xl border border-slate-200 bg-white opacity-95 hover:opacity-100 hover:border-[#023b55]/20 transition">
                    <div class="relative aspect-[3/4] overflow-hidden bg-slate-100">
                        <img src="{{ $u }}" alt="{{ $arc->title }}" width="400" height="533" loading="lazy" class="h-full w-full object-cover grayscale hover:grayscale-0 transition">
                        <span class="absolute inset-0 bg-slate-900/5"></span>
                        <span class="absolute right-2 top-2 rounded-lg bg-slate-700 px-2 py-1 text-[10px] font-bold text-white">منتهي {{ \Carbon\Carbon::parse($arc->valid_until)->format('d/m') }}</span>
                    </div>
                    <div class="p-3">
                        <h3 class="line-clamp-2 text-xs font-bold text-slate-700">{{ $arc->title }}</h3>
                        <div class="mt-1 text-[11px] text-slate-500">{{ \Carbon\Carbon::parse($arc->valid_from)->format('d/m/Y') }} → {{ \Carbon\Carbon::parse($arc->valid_until)->format('d/m/Y') }}</div>
                    </div>
                </a>
            @endforeach
        </div>
    </section>
    @endif
    </div>
</x-layouts.app>
