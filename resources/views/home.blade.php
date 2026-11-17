@php
    use App\Support\R2Url;
    $siteName = config('app.name', 'مجلاتنا');
    $searchQuery = $searchQuery ?? request('q', '');
    $selectedRetailerSlug = request('retailer');
    $r2DiskUrl = R2Url::base();
    // max-image-preview:large (NOT "max-image-size" — that directive is invalid).
    // Filtered/paginated combos are noindex,follow so Googlebot can crawl and
    // see the directive while the clean hub stays indexable.
    $robotsMeta = request()->filled('q') || request()->filled('retailer') || request()->filled('type') || (int) request('page', 1) > 1
        ? 'noindex, follow'
        : 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1';
    $baseUrl = rtrim((string) config('app.url'), '/');
    $homeUrl = $baseUrl.'/';
@endphp

<x-layouts.app
    :meta-title="$searchQuery ? 'نتائج البحث عن ' . $searchQuery : 'أحدث عروض ومجلات السوبرماركت في مصر اليوم'"
    :meta-description="'تصفح أحدث عروض وتخفيضات سلاسل السوبرماركت في مصر (كازيون، كارفور، بيم، فتح الله). أسعار السلع والجبن والزيوت محدثة ومفهرسة يومياً.'"
    :robots="$robotsMeta"
>
    @push('schema')
        @php
            $homeOrganizationId = $homeUrl . '#organization';
            $homeWebsiteId = $homeUrl . '#website';
            // Real social profiles only — NEVER emit an empty "sameAs": []
            // (Rich Results flags an empty array as an invalid property).
            $homeSocialLinks = array_values(array_filter((array) config('app.social_links', [])));
            $homeOrganization = [
                '@type' => 'Organization',
                '@id' => $homeOrganizationId,
                'name' => $siteName,
                'url' => $homeUrl,
                'logo' => [
                    '@type' => 'ImageObject',
                    // The REAL 140x36 logo — favicon.svg is a square icon and
                    // fails Google's Organization logo image guidelines.
                    'url' => $homeUrl.'logo.webp',
                    'width' => 140,
                    'height' => 36,
                ],
                'areaServed' => 'EG',
                'knowsAbout' => [
                    'عروض كارفور مصر',
                    'تخفيضات كازيون',
                    'عروض بيم الأسبوعية',
                    'أسعار السلع في مصر',
                ],
            ];
            if ($homeSocialLinks !== []) {
                $homeOrganization['sameAs'] = $homeSocialLinks;
            }
            $homeWebsite = [
                '@type' => 'WebSite',
                '@id' => $homeWebsiteId,
                'name' => $siteName,
                'url' => $homeUrl,
                'publisher' => ['@id' => $homeOrganizationId],
                'potentialAction' => [
                    '@type' => 'SearchAction',
                    'target' => [
                        '@type' => 'EntryPoint',
                        // rtrim() kills the double-slash bug ("...com/?q=..." not "...com//?q=...")
                        'urlTemplate' => $homeUrl . '?q={search_term_string}',
                    ],
                    'query-input' => 'required name=search_term_string',
                ],
            ];
            if ($homeSocialLinks !== []) {
                $homeWebsite['sameAs'] = $homeSocialLinks;
            }
            $homeGraph = [$homeOrganization, $homeWebsite];

            // Homepage ItemList (2026 SEO): the top 12 ACTIVE PUBLISHED flyers
            // — built purely from the ALREADY-paginated $flyers collection:
            // zero extra SQL queries. Skipped on search-result views (they are
            // noindex and must not claim to be the homepage list).
            if (! request()->filled('q') && $flyers->isNotEmpty()) {
                $topFlyers = $flyers->getCollection()->take(12)->values();
                $homeGraph[] = [
                    '@type' => 'ItemList',
                    'name' => 'أحدث عروض ومجلات السوبرماركت السارية في مصر اليوم',
                    'numberOfItems' => $topFlyers->count(),
                    'itemListElement' => $topFlyers->map(fn ($listFlyer, $idx) => [
                        '@type' => 'ListItem',
                        'position' => $idx + 1,
                        // Canonical host ONLY — never the request host.
                        'url' => $baseUrl.route('flyers.show', ['slug' => $listFlyer->slug], false),
                        'name' => $listFlyer->title,
                    ])->all(),
                ];
            }

            // FAQ (3 concise Q&As mirroring the visible H3/pair section below).
            if (! request()->filled('q')) {
                $homeFaq = [
                    [
                        'q' => 'كيف أعرف أقوى عروض السوبرماركت في مصر اليوم؟',
                        'a' => 'تصفح قائمة «أحدث مجلات العروض السارية» في هذه الصفحة، وشاهد جدول ملخص العروض المحدث يومياً لكارفور وكازيون وبيم وهايبر وان.',
                    ],
                    [
                        'q' => 'هل أسعار مجلات العروض هنا مطابقة لأسعار الفروع؟',
                        'a' => 'نعم، الأسعار مستخرجة تلقائياً من مجلات المتاجر الرسمية المنشورة في مصر، وتظل خاضعة لشروط كل سلسلة تجارية حتى نفاذ الكمية.',
                    ],
                    [
                        'q' => 'كيف أقارن سعر السلعة الواحدة بين المتاجر؟',
                        'a' => 'ادخل إلى أي مجلة عرض وستجد جدول أسعار مفصلاً مع «سعر الوحدة» لكل سلعة (ج.م / كجم أو لتر) لتقارن القيمة الحقيقية بين المتاجر بضغطة واحدة.',
                    ],
                ];
                $homeGraph[] = [
                    '@type' => 'FAQPage',
                    'mainEntity' => collect($homeFaq)->map(fn ($entry) => [
                        '@type' => 'Question',
                        'name' => $entry['q'],
                        'acceptedAnswer' => ['@type' => 'Answer', 'text' => $entry['a']],
                    ])->all(),
                ];
            }

            $homeUnified = [
                '@context' => 'https://schema.org',
                '@graph' => $homeGraph,
            ];
        @endphp
        <script type="application/ld+json">{!! json_encode($homeUnified, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) !!}</script>
    @endpush

    <!-- Header Hero & Stores Filter Carousel - Navy structure -->
    <section class="mb-8">
        <div class="mb-6 rounded-2xl border border-[#023b55]/10 bg-white p-6 sm:p-8 shadow-sm">
            <h1 class="text-2xl font-black text-[#023b55] sm:text-3xl">عروض ومجلات التخفيضات في مصر اليوم</h1>
            <p class="mt-2 text-sm text-slate-600 max-w-3xl">
                دليلك المباشر لتوفير ميزانية البيت. نجمع لك مجلات عروض كارفور، كازيون، بيم، وهايبر وان لحظة نزولها مع استخراج ذكي لأسعار كل سلعة لتسهيل المقارنة.
            </p>
        </div>

        <!-- أزرار المتاجر السريعة (Store Filter Pills) - Green active, Navy inactive -->
        <div class="flex items-center gap-2 overflow-x-auto pb-2 scrollbar-none">
            <a
                href="{{ $homeUrl }}"
                class="inline-flex shrink-0 items-center gap-1.5 rounded-full px-4 py-2 text-xs font-bold transition {{ empty($selectedRetailerSlug) ? 'bg-[#039652] text-white shadow-sm' : 'border border-slate-200 bg-white text-[#023b55] hover:border-[#039652]/30 hover:bg-slate-50' }}"
            >
                جميع المتاجر
            </a>
            @foreach ($retailers as $ret)
                <a
                    href="{{ route('retailers.show', $ret->slug) }}"
                    class="inline-flex shrink-0 items-center gap-2 rounded-full border px-3.5 py-1.5 text-xs font-bold transition border-slate-200 bg-white text-[#023b55] hover:border-[#039652]/30 hover:bg-slate-50"
                >
                    @if ($ret->logo_path)
                        <img src="{{ R2Url::asset($ret->logo_path) }}" alt="{{ $ret->name }}" width="16" height="16" class="h-4 w-4 rounded-full object-contain">
                    @endif
                    <span>{{ $ret->name }}</span>
                </a>
            @endforeach
        </div>
    </section>

    <!-- Active Search Notification Bar -->
    @if (request()->filled('q'))
        <div class="mb-6 flex flex-col gap-2 rounded-xl border border-[#039652]/20 bg-[#039652]/10 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-xs font-bold text-[#023b55] sm:text-sm">
                نتائج البحث عن: <span class="font-black text-[#039652]">"{{ $searchQuery }}"</span>
                — تم العثور على <span class="font-black">{{ $matchingItems?->total() ?? 0 }}</span> منتج و <span class="font-black">{{ $flyers->total() }}</span> مجلة.
            </p>
            <a href="{{ $homeUrl }}" class="inline-flex shrink-0 items-center gap-1 rounded-full bg-[#023b55] px-3 py-1 text-xs font-black text-white hover:bg-[#039652] transition">
                إلغاء البحث ✕
            </a>
        </div>
    @endif

    <!-- Dedicated Matching Products Section (search only) -->
    @if (request()->filled('q') && isset($matchingItems) && $matchingItems->isNotEmpty())
        <section class="mb-12 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="mb-4 text-lg font-black text-[#023b55]">السلع والمنتجات المطابقة لبحثك ('{{ $searchQuery }}')</h2>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                @foreach ($matchingItems as $item)
                    <div class="group flex flex-col rounded-xl border border-slate-200 bg-white p-3 shadow-sm transition hover:border-[#039652] hover:shadow-md">
                        <div class="flex items-center justify-between gap-1.5">
                            <a href="{{ route('retailers.show', $item->flyer->retailer->slug) }}" class="inline-flex items-center gap-1 rounded-full bg-[#023b55]/10 px-2 py-0.5 text-[10px] font-bold text-[#023b55] hover:bg-[#023b55] hover:text-white transition">
                                {{ $item->flyer->retailer->name }}
                            </a>
                            @if ($item->discount_percent)
                                <span class="rounded-full bg-[#039652]/10 px-1.5 py-0.5 text-[10px] font-black text-[#027a42]">-{{ round((float) $item->discount_percent) }}%</span>
                            @endif
                        </div>
                        <a href="{{ route('flyers.show', $item->flyer->slug) }}#item-{{ $item->id }}" class="mt-2 flex flex-1 flex-col hover:text-[#039652]" aria-label="{{ $item->product_name }} - {{ $item->flyer->retailer->name }}">
                            <div class="line-clamp-2 text-xs font-bold text-[#023b55] group-hover:text-[#039652] transition-colors">{{ $item->product_name }}</div>
                            <div class="mt-auto pt-2">
                                @if ($item->old_price)
                                    <div class="text-[11px] text-slate-600 line-through">{{ number_format((float) $item->old_price, 2) }} ج.م</div>
                                @endif
                                <div class="text-sm font-black text-[#039652]">{{ number_format((float) $item->sale_price, 2) }} ج.م</div>
                            </div>
                        </a>
                    </div>
                @endforeach
            </div>
            <div class="mt-6">
                {{ $matchingItems->links() }}
            </div>
        </section>
    @endif

    <!-- Zero Results State -->
    @if (request()->filled('q') && isset($matchingItems) && $matchingItems->isEmpty() && $flyers->isEmpty())
        <section class="mb-12 rounded-2xl border border-dashed border-slate-300 bg-white p-8 sm:p-12 text-center shadow-sm">
            <div class="mx-auto max-w-xl">
                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-100 text-slate-400">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M10 10a.01.01 0 01.01-.01H10a.01.01 0 01-.01.01z"/></svg>
                </div>
                <h3 class="mt-4 text-base font-black text-[#023b55]">لم نجد أي سلع أو مجلات مطابقة لبحثك عن '{{ $searchQuery }}'</h3>
                <p class="mt-2 text-sm text-slate-500">جرب كلمات أكثر عمومية أو استخدم أحد الاقتراحات التالية:</p>
                <div class="mt-4 flex flex-wrap justify-center gap-2">
                    @foreach (['أرز','زيت','سكر','مسحوق غسيل','جبنة','تونة'] as $tag)
                        <a href="{{ route('home', ['q' => $tag]) }}" class="rounded-full border border-slate-200 bg-white px-3 py-1.5 text-xs font-bold text-[#023b55] hover:border-[#039652]/30 hover:bg-[#039652]/10 hover:text-[#039652] transition">{{ $tag }}</a>
                    @endforeach
                </div>
                <a href="{{ $homeUrl }}" class="mt-6 inline-flex rounded-xl bg-[#039652] px-4 py-2 text-xs font-black text-white hover:bg-[#027a42] transition">العودة لجميع العروض</a>
            </div>
        </section>
    @endif

    <!-- المجلات السارية حالياً (Active Flyers Grid) - Navy headings -->
    {{-- #latest-flyers: the global nav's "جميع المجلات" links HERE (never to a
    ?type=magazines duplicate URL); scroll-mt clears the sticky header. --}}
    <section id="latest-flyers" class="mb-12 scroll-mt-20">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-lg font-black text-[#023b55] sm:text-xl">أحدث مجلات العروض السارية</h2>
            <span class="text-xs font-semibold text-slate-500">{{ $flyers->total() }} مجلة سارية</span>
        </div>

        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
            @forelse ($flyers as $flyer)
                @php
                    $coverPage = $flyer->coverPage;
                    // Lightweight 400px cover thumbnail (falls back to the
                    // master image until the thumb exists).
                    $coverUrl = R2Url::cardThumb($coverPage);
                @endphp
                <article class="group flex flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition hover:shadow-md">
                    <a href="{{ route('flyers.show', $flyer->slug) }}" class="relative aspect-[3/4] overflow-hidden bg-slate-100">
                        <img
                            src="{{ $coverUrl }}"
                            alt="{{ $flyer->title }}"
                            width="400"
                            height="533"
                            {{-- LCP: the first FOUR above-the-fold covers are eager
                            (card 1 only gets fetchpriority=high) to match the
                            4-column desktop grid; the rest lazy-load. --}}
                            @if ($loop->index === 0) loading="eager" fetchpriority="high" decoding="async"
                            @elseif ($loop->index < 4) loading="eager" decoding="async"
                            @else loading="lazy" decoding="async" @endif
                            class="h-full w-full object-cover transition duration-300 group-hover:scale-105"
                        >
                        <span class="absolute right-2 top-2 rounded-lg bg-slate-900/80 px-2 py-1 text-[11px] font-bold text-white backdrop-blur">
                            {{ $flyer->pages_count ?? $flyer->total_pages }} صفحة
                        </span>
                    </a>

                    <div class="flex flex-1 flex-col p-4">
                        <div class="flex items-center gap-1.5 text-xs font-semibold text-[#023b55]">
                            <span>{{ $flyer->retailer->name }}</span>
                        </div>

                        <h3 class="mt-1 line-clamp-2 text-sm font-bold text-[#023b55] group-hover:text-[#039652]">
                            <a href="{{ route('flyers.show', $flyer->slug) }}">{{ $flyer->title }}</a>
                        </h3>

                        @php
                            $today = \Carbon\Carbon::today('Africa/Cairo');
                            $from = \Carbon\Carbon::parse($flyer->valid_from, 'Africa/Cairo');
                            $until = \Carbon\Carbon::parse($flyer->valid_until, 'Africa/Cairo');
                        @endphp
                        <div class="mt-3 flex items-center justify-between border-t border-slate-100 pt-3 text-[11px]">
                            @if ($flyer->isExpired())
                                <span class="text-slate-600">انتهى: {{ $until->format('d/m/Y') }}</span>
                                <span class="rounded bg-slate-100 px-2 py-0.5 text-[10px] font-bold text-slate-600">منتهي</span>
                            @elseif ($from->isFuture())
                                <span class="font-bold text-[#023b55]">يبدأ: {{ $from->format('d/m/Y') }}</span>
                                <span class="rounded bg-[#fcc023] px-2 py-0.5 text-[10px] font-bold text-slate-900">قريباً</span>
                            @else
                                <span class="text-slate-600">سارٍ حتى: {{ $until->format('d/m/Y') }}</span>
                                <span class="rounded bg-[#039652]/10 px-2 py-0.5 font-bold text-[#027a42]">سارٍ الآن</span>
                            @endif
                        </div>
                    </div>
                </article>
            @empty
                <div class="col-span-full rounded-2xl border border-dashed border-slate-300 bg-white p-12 text-center text-sm text-slate-500">
                    لا توجد مجلات عروض مطابقة للبحث حالياً.
                </div>
            @endforelse
        </div>

        <div class="mt-6">
            {{ $flyers->links() }}
        </div>
    </section>

    <!-- جدول / بطاقات أقوى السلع المخفضة (Hot Items Spotlight) - Deep-linked -->
    @if ($hotItems->isNotEmpty())
        <section id="hot-deals" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm scroll-mt-20">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-lg font-black text-[#023b55]">أقوى تخفيضات السلع والمنتجات اليوم</h2>
                <a href="{{ $homeUrl }}#latest-flyers" class="text-xs font-bold text-[#039652] hover:underline">عرض الكل ←</a>
            </div>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                @foreach ($hotItems as $item)
                    <div class="group flex flex-col rounded-xl border border-slate-200 bg-white p-3 shadow-sm transition hover:border-[#039652] hover:shadow-md">
                        <div class="flex items-center justify-between gap-1.5">
                            <a href="{{ route('retailers.show', $item->flyer->retailer->slug) }}" class="inline-flex items-center gap-1 rounded-full bg-[#023b55]/10 px-2 py-0.5 text-[10px] font-bold text-[#023b55] hover:bg-[#023b55] hover:text-white transition">
                                {{ $item->flyer->retailer->name }}
                            </a>
                            @if ($item->discount_percent)
                                <span class="rounded-full bg-[#039652]/10 px-1.5 py-0.5 text-[10px] font-black text-[#027a42]">-{{ round((float) $item->discount_percent) }}%</span>
                            @endif
                        </div>
                        <a href="{{ route('flyers.show', $item->flyer->slug) }}#item-{{ $item->id }}" class="mt-2 flex flex-1 flex-col" aria-label="{{ $item->product_name }} - {{ $item->flyer->retailer->name }}">
                            <div class="line-clamp-2 text-xs font-bold text-[#023b55] group-hover:text-[#039652] transition-colors">{{ $item->product_name }}</div>
                            <div class="mt-auto pt-2">
                                @if ($item->old_price)
                                    <div class="text-[11px] text-slate-600 line-through">{{ number_format((float) $item->old_price, 2) }} ج.م</div>
                                @endif
                                <div class="text-sm font-black text-[#039652]">{{ number_format((float) $item->sale_price, 2) }} ج.م</div>
                            </div>
                        </a>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    <!-- ملخص عروض السوبرماركت في مصر اليوم + FAQ — built from the ALREADY-loaded
         $flyers collection: ZERO extra SQL queries. Sits above the footer as the
         final GEO/AEO chunk. -->
    @if (! request()->filled('q') && $flyers->isNotEmpty())
        <section class="mt-12 space-y-6">
            <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-black text-[#023b55]">ملخص عروض السوبرماركت في مصر اليوم</h2>
                <p class="mt-1 text-xs text-slate-500">جدول سريع لأحدث مجلات العروض السارية الآن — يتحدث تلقائياً مع كل نزول جديد من المتاجر.</p>
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full min-w-[560px] text-right text-xs">
                        <thead class="border-b border-[#023b55]/10 bg-[#023b55]/5 text-[#023b55]">
                            <tr>
                                <th scope="col" class="p-3 font-bold">المتجر</th>
                                <th scope="col" class="p-3 font-bold">المجلة</th>
                                <th scope="col" class="p-3 font-bold">الفترة</th>
                                <th scope="col" class="p-3 font-bold">الصفحات</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($flyers->getCollection()->take(12) as $summaryFlyer)
                                <tr class="hover:bg-[#039652]/5">
                                    <td class="p-3 font-bold text-[#023b55]">
                                        <a href="{{ route('retailers.show', $summaryFlyer->retailer->slug) }}" class="hover:text-[#039652]">{{ $summaryFlyer->retailer->name }}</a>
                                    </td>
                                    <td class="p-3">
                                        <a href="{{ route('flyers.show', $summaryFlyer->slug) }}" class="font-medium text-slate-700 hover:text-[#039652]">{{ $summaryFlyer->title }}</a>
                                    </td>
                                    <td class="p-3 text-slate-500">
                                        {{ \Carbon\Carbon::parse($summaryFlyer->valid_from, 'Africa/Cairo')->format('d/m/Y') }} →
                                        {{ \Carbon\Carbon::parse($summaryFlyer->valid_until, 'Africa/Cairo')->format('d/m/Y') }}
                                    </td>
                                    <td class="p-3 text-slate-500">{{ $summaryFlyer->pages_count ?? $summaryFlyer->total_pages }} صفحة</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="rounded-2xl border border-[#023b55]/10 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-black text-[#023b55]">أسئلة شائعة عن عروض السوبرماركت في مصر</h2>
                <div class="mt-4 space-y-4">
                    @foreach ($homeFaq as $faqEntry)
                        <div>
                            <h3 class="text-sm font-black text-[#023b55]">{{ $faqEntry['q'] }}</h3>
                            <p class="mt-1 max-w-3xl text-xs leading-relaxed text-slate-600">{{ $faqEntry['a'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
    @endif
</x-layouts.app>
