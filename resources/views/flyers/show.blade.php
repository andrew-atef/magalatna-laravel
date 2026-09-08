@php
    $r2DiskUrl = rtrim((string) config('filesystems.disks.r2.url'), '/');
    $firstPage = $flyer->pages->first();
    $coverImage = $firstPage ? $r2DiskUrl . '/' . $firstPage->image_path : url('/img/og-cover.png');
    $pageUrl = route('flyers.show', $flyer->slug);
    $cleanTitle = $flyer->title;

    // BLUF — Bulletproof Arabic via Flyer accessor
    $bluf = $flyer->bluf_summary;
@endphp

<x-layouts.app
    :meta-title="$cleanTitle"
    :meta-description="$bluf"
    :og-title="$cleanTitle"
    :og-description="$bluf"
    :og-image="$coverImage"
    og-type="article"
>
    @push('schema')
        @php
            // 1. Breadcrumb Schema
            $breadcrumbSchema = [
                '@context' => 'https://schema.org',
                '@type' => 'BreadcrumbList',
                'itemListElement' => [
                    ['@type' => 'ListItem', 'position' => 1, 'name' => 'الرئيسية', 'item' => url('/')],
                    ['@type' => 'ListItem', 'position' => 2, 'name' => $flyer->retailer->name, 'item' => route('retailers.show', $flyer->retailer->slug)],
                    ['@type' => 'ListItem', 'position' => 3, 'name' => $cleanTitle, 'item' => $pageUrl],
                ],
            ];

            // 2. Special Announcement Schema (مناسب جداً للتخفيضات ومجلات الأسعار المؤقتة)
            // validFrom مستقبلي للمجلات القادمة — يمنع تناقض جوجل الزمني
            $announcementSchema = [
                '@context' => 'https://schema.org',
                '@type' => 'SpecialAnnouncement',
                'name' => $cleanTitle,
                'text' => $bluf,
                'datePosted' => $flyer->created_at->toIso8601String(),
                'expires' => \Carbon\Carbon::parse($flyer->valid_until)->endOfDay()->toIso8601String(),
                'announcementLocation' => [
                    '@type' => 'LocalBusiness',
                    'name' => $flyer->retailer->name,
                    'address' => ['@type' => 'PostalAddress', 'addressCountry' => 'EG'],
                ],
            ];
            // أضف validFrom صريح لـ Google إذا كان العرض مستقبلي
            $validFromForSchema = \Carbon\Carbon::parse($flyer->valid_from, 'Africa/Cairo')->startOfDay()->toIso8601String();
            if (\Carbon\Carbon::parse($flyer->valid_from, 'Africa/Cairo')->isFuture()) {
                $announcementSchema['availabilityStarts'] = $validFromForSchema;
            }

            // 3. ItemList Schema للمنتجات والأسعار المستخرجة
            $itemListSchema = [
                '@context' => 'https://schema.org',
                '@type' => 'ItemList',
                'name' => 'قائمة أسعار وسلع ' . $cleanTitle,
                'numberOfItems' => $flyer->items->count(),
                'itemListElement' => $flyer->items->take(50)->values()->map(function ($item, $idx) use ($pageUrl, $flyer) {
                    $hasOld = $item->old_price && (float)$item->old_price > (float)$item->sale_price;
                    // مصداقية زمنية: يبدأ قريباً = PreOrder، منتهي = OutOfStock
                    $fromForOffer = \Carbon\Carbon::parse($flyer->valid_from, 'Africa/Cairo');
                    $untilForOffer = \Carbon\Carbon::parse($flyer->valid_until, 'Africa/Cairo');
                    $todayForOffer = \Carbon\Carbon::today('Africa/Cairo');
                    $availability = $fromForOffer->isFuture() ? 'https://schema.org/PreOrder' : ($untilForOffer->isPast() ? 'https://schema.org/OutOfStock' : 'https://schema.org/InStock');
                    $offer = [
                        '@type' => 'Offer',
                        'price' => number_format((float) $item->sale_price, 2, '.', ''),
                        'priceCurrency' => 'EGP',
                        'priceValidUntil' => $untilForOffer->format('Y-m-d'),
                        'availability' => $availability,
                        'seller' => ['@type' => 'Organization', 'name' => $flyer->retailer->name],
                    ];
                    if ($fromForOffer->isFuture()) {
                        $offer['availabilityStarts'] = $fromForOffer->format('Y-m-d');
                    }

                    if ($hasOld) {
                        $offer['priceSpecification'] = [
                            '@type' => 'UnitPriceSpecification',
                            'priceType' => 'https://schema.org/StrikethroughPrice',
                            'price' => number_format((float) $item->old_price, 2, '.', ''),
                            'priceCurrency' => 'EGP',
                        ];
                    }

                    return [
                        '@type' => 'ListItem',
                        'position' => $idx + 1,
                        'item' => [
                            '@type' => 'Product',
                            'name' => $item->product_name,
                            'url' => $pageUrl . '#item-' . $item->id,
                            'brand' => ['@type' => 'Brand', 'name' => $item->brand?->name ?: $item->product_name],
                            'offers' => $offer,
                        ],
                    ];
                })->all(),
            ];
        @endphp

        <script type="application/ld+json">{!! json_encode($breadcrumbSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
        <script type="application/ld+json">{!! json_encode($announcementSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
        <script type="application/ld+json">{!! json_encode($itemListSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
    @endpush

    <!-- Breadcrumb -->
    <nav class="mb-4 text-xs font-semibold text-slate-500" aria-label="Breadcrumb">
        <ol class="flex items-center gap-1.5">
            <li><a href="/" class="hover:text-sky-600">الرئيسية</a></li>
            <li>/</li>
            <li><a href="{{ route('retailers.show', $flyer->retailer->slug) }}" class="hover:text-sky-600">{{ $flyer->retailer->name }}</a></li>
            <li>/</li>
            <li class="text-slate-900 truncate max-w-xs">{{ $cleanTitle }}</li>
        </ol>
    </nav>

    <!-- Flyer Header Card -->
    <article class="mb-8 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-4 border-b border-slate-100 pb-4">
            <div>
                <span class="inline-block rounded-lg bg-sky-50 px-2.5 py-1 text-xs font-bold text-sky-700">
                    {{ $flyer->retailer->name }}
                </span>
                <h1 class="mt-2 text-2xl font-black text-slate-900 sm:text-3xl">{{ $cleanTitle }}</h1>
            </div>

            @php
                $today = \Carbon\Carbon::today('Africa/Cairo');
                $from = \Carbon\Carbon::parse($flyer->valid_from, 'Africa/Cairo');
                $until = \Carbon\Carbon::parse($flyer->valid_until, 'Africa/Cairo');
            @endphp
            <div class="flex items-center gap-3">
                @if ($from->isFuture())
                    <span class="rounded-xl border border-amber-200 bg-amber-50 px-3 py-1.5 text-xs font-bold text-amber-700">
                        يبدأ قريباً {{ $from->format('d/m/Y') }}
                    </span>
                    <span class="rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-bold text-slate-600">
                        سارٍ من {{ $from->format('d/m/Y') }} حتى {{ $until->format('d/m/Y') }}
                    </span>
                @elseif ($until->isPast())
                    <span class="rounded-xl border border-slate-200 bg-slate-100 px-3 py-1.5 text-xs font-bold text-slate-600">
                        انتهى في {{ $until->format('d/m/Y') }}
                    </span>
                @else
                    <span class="rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-bold text-emerald-700">
                        سارٍ حتى {{ $until->format('d/m/Y') }}
                    </span>
                @endif
            </div>
        </div>

        <!-- 2-Sentence BLUF Summary Box for AI Engines (Perplexity / ChatGPT / AI Overviews) -->
        <div class="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm font-medium leading-relaxed text-slate-700">
            <p class="font-bold text-slate-900 mb-1">📌 خلاصة العرض (سريعة ومباشرة):</p>
            <p>{{ $bluf }}</p>
        </div>
    </article>

    <!-- عارض المجلة مصورة (Image Viewer - WebP from R2) - Desktop Optimized -->
    <section class="mb-12" id="flyer-viewer">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-lg font-black text-slate-900">تصفح صفحات المجلة ({{ $flyer->pages->count() }} صفحة)</h2>
            <span class="hidden text-xs text-slate-500 sm:inline">اضغط على أي صفحة للتكبير والتنقل</span>
        </div>

        {{-- Single focused column max-w-3xl for legibility: A4 vertical pages need full width on desktop --}}
        <div class="grid grid-cols-1 gap-6 max-w-3xl mx-auto">
            @foreach ($flyer->pages->sortBy('page_number') as $page)
                <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-100 bg-slate-50 px-4 py-2 text-xs font-bold text-slate-600">
                        صفحة رقم {{ $page->page_number }}
                    </div>
                    <button type="button" data-index="{{ $loop->index }}" aria-label="تكبير صفحة {{ $page->page_number }}" class="lightbox-trigger block w-full cursor-zoom-in focus:outline-none focus:ring-2 focus:ring-sky-500 focus:ring-offset-2">
                        <img
                            src="{{ $r2DiskUrl . '/' . $page->image_path }}"
                            alt="{{ $cleanTitle }} - صفحة {{ $page->page_number }}"
                            width="1200"
                            height="1600"
                            loading="lazy"
                            class="w-full object-contain"
                        >
                    </button>
                </div>
            @endforeach
        </div>
    </section>

    <!-- Lightbox Modal - Pure JS, Zero CDN -->
    <div id="flyer-lightbox" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/90 p-4 backdrop-blur-sm" role="dialog" aria-modal="true" aria-label="عارض الصفحات">
        <div class="relative flex h-full w-full max-w-5xl flex-col">
            <!-- Top Bar: Page indicator + Controls -->
            <div class="mb-3 flex items-center justify-between text-white">
                <span id="lightbox-indicator" class="rounded-full bg-white/15 px-3 py-1 text-xs font-bold backdrop-blur">صفحة 1 من 1</span>
                <div class="flex items-center gap-2">
                    <button type="button" id="lightbox-zoom" class="rounded-full bg-white/15 p-2.5 text-white backdrop-blur transition hover:bg-white/25" aria-label="تكبير">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM10 7v6m3-3H7"/></svg>
                    </button>
                    <button type="button" id="lightbox-close" class="rounded-full bg-white p-2.5 text-slate-900 shadow transition hover:bg-slate-100" aria-label="إغلاق">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
            </div>
            <!-- Image Container -->
            <div id="lightbox-stage" class="relative flex flex-1 items-center justify-center overflow-hidden rounded-xl bg-slate-800">
                <button type="button" id="lightbox-prev" class="absolute left-2 z-10 rounded-full bg-white/90 p-3 text-slate-900 shadow-lg transition hover:bg-white sm:left-4" aria-label="السابق">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                </button>
                <img id="lightbox-image" src="" alt="" class="max-h-[75vh] max-w-full object-contain transition-transform duration-200" style="transform: scale(1);">
                <button type="button" id="lightbox-next" class="absolute right-2 z-10 rounded-full bg-white/90 p-3 text-slate-900 shadow-lg transition hover:bg-white sm:right-4" aria-label="التالي">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                </button>
            </div>
            <p class="mt-3 text-center text-xs text-white/70">استخدم ← → للتنقل، +/- للتكبير، Esc للإغلاق</p>
        </div>
    </div>

    <style>#flyer-lightbox:not(.hidden){display:flex}#flyer-lightbox.hidden{display:none}</style>
    <script>
    document.addEventListener('DOMContentLoaded', function(){
        const pages = @json($flyer->pages->sortBy('page_number')->values()->map(fn($p) => ['src' => $r2DiskUrl.'/'.$p->image_path, 'alt' => $cleanTitle.' - صفحة '.$p->page_number]));
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

    <!-- جدول الأسعار والسلع المكتشفة في المجلة (Structured Items Table) -->
    <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <h2 class="mb-2 text-lg font-black text-slate-900">جدول السلع والأسعار المفصلة في هذا العرض</h2>
        <p class="mb-6 text-xs text-slate-500">تم استخراج وقراءة هذه الأسعار تلقائياً وتدقيقها لضمان سهولة المقارنة والبحث.</p>

        <div class="overflow-x-auto">
            <table class="w-full text-right text-xs">
                <thead class="border-b border-slate-200 bg-slate-50 text-slate-700">
                    <tr>
                        <th class="p-3 font-bold">اسم السلعة</th>
                        <th class="p-3 font-bold">الماركة</th>
                        <th class="p-3 font-bold">الوحدة</th>
                        <th class="p-3 font-bold">سعر العرض</th>
                        <th class="p-3 font-bold">السعر القديم</th>
                        <th class="p-3 font-bold">نسبة التوفير</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 font-medium text-slate-800">
                    @forelse ($flyer->items as $item)
                        <tr id="item-{{ $item->id }}" class="hover:bg-slate-50">
                            <td class="p-3 font-bold text-slate-900">{{ $item->product_name }}</td>
                            <td class="p-3 text-slate-500">{{ $item->brand?->name ?: '—' }}</td>
                            <td class="p-3 text-slate-500">{{ $item->unit ?: 'قطعة' }}</td>
                            <td class="p-3 text-sm font-black text-emerald-600">{{ number_format((float) $item->sale_price, 2) }} ج.م</td>
                            <td class="p-3 text-slate-400">
                                @if ($item->old_price)
                                    <span class="line-through">{{ number_format((float) $item->old_price, 2) }} ج.م</span>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="p-3">
                                @if ($item->discount_percent)
                                    <span class="rounded bg-rose-50 px-2 py-0.5 text-[11px] font-bold text-rose-600">
                                        خصم {{ round((float) $item->discount_percent) }}%
                                    </span>
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="p-6 text-center text-slate-400">لا توجد سلع مسجلة لهذه المجلة بعد.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</x-layouts.app>
