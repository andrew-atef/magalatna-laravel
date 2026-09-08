@php
    use App\Support\R2Url;
    $r2DiskUrl = R2Url::base();
    $firstPage = $flyer->pages->first();
    $coverImage = $firstPage ? R2Url::asset($firstPage->image_path) : url('/img/og-cover.png');
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
            $siteName = config('app.name', 'مجلاتنا');
            $organizationId = url('/') . '#organization';
            $websiteId = url('/') . '#website';
            $breadcrumbId = $pageUrl . '#breadcrumb';
            $eventId = $pageUrl . '#event';
            $itemListId = $pageUrl . '#itemlist';

            $graph = [
                [
                    '@type' => 'Organization',
                    '@id' => $organizationId,
                    'name' => $siteName,
                    'url' => url('/'),
                    'logo' => [
                        '@type' => 'ImageObject',
                        'url' => url('/favicon.svg'),
                        'width' => 140,
                        'height' => 36,
                    ],
                    'areaServed' => [
                        '@type' => 'Country',
                        'name' => 'EG',
                    ],
                ],
                [
                    '@type' => 'WebSite',
                    '@id' => $websiteId,
                    'url' => url('/'),
                    'name' => $siteName,
                    'publisher' => ['@id' => $organizationId],
                ],
                [
                    '@type' => 'BreadcrumbList',
                    '@id' => $breadcrumbId,
                    'itemListElement' => [
                        ['@type' => 'ListItem', 'position' => 1, 'name' => 'الرئيسية', 'item' => url('/')],
                        ['@type' => 'ListItem', 'position' => 2, 'name' => $flyer->retailer->name, 'item' => route('retailers.show', $flyer->retailer->slug)],
                        ['@type' => 'ListItem', 'position' => 3, 'name' => $cleanTitle, 'item' => $pageUrl],
                    ],
                ],
                [
                    '@type' => 'SaleEvent',
                    '@id' => $eventId,
                    'name' => $cleanTitle,
                    'description' => $bluf,
                    'startDate' => \Carbon\Carbon::createFromFormat('Y-m-d', $flyer->valid_from->format('Y-m-d'), 'Africa/Cairo')->startOfDay()->toIso8601String(),
                    'endDate' => \Carbon\Carbon::createFromFormat('Y-m-d', $flyer->valid_until->format('Y-m-d'), 'Africa/Cairo')->endOfDay()->toIso8601String(),
                    'eventStatus' => $flyer->isExpired() ? 'https://schema.org/EventCancelled' : 'https://schema.org/EventScheduled',
                    'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
                    'location' => [
                        '@type' => 'Place',
                        'name' => 'فروع ' . $flyer->retailer->name . ' بمصر',
                        'address' => [
                            '@type' => 'PostalAddress',
                            'addressCountry' => 'EG',
                        ],
                    ],
                    'organizer' => ['@id' => $organizationId],
                ],
                [
                    '@type' => 'ItemList',
                    '@id' => $itemListId,
                    'name' => 'قائمة أسعار وسلع ' . $cleanTitle,
                    'numberOfItems' => $flyer->items->count(),
                    'itemListElement' => $flyer->items->take(50)->values()->map(function ($item, $idx) use ($pageUrl, $flyer, $coverImage) {
                        $hasOld = $item->old_price && (float) $item->old_price > (float) $item->sale_price;
                        $offer = [
                            '@type' => 'Offer',
                            'url' => $pageUrl . '#item-' . $item->id,
                            'price' => number_format((float) $item->sale_price, 2, '.', ''),
                            'priceCurrency' => 'EGP',
                            'priceValidUntil' => \Carbon\Carbon::createFromFormat('Y-m-d', $flyer->valid_until->format('Y-m-d'), 'Africa/Cairo')->format('Y-m-d'),
                            'availability' => $flyer->isExpired() ? 'https://schema.org/Discontinued' : 'https://schema.org/InStock',
                            'seller' => ['@type' => 'Organization', 'name' => $flyer->retailer->name],
                        ];
                        if ($hasOld) {
                            $offer['priceSpecification'] = [
                                '@type' => 'UnitPriceSpecification',
                                'priceType' => 'https://schema.org/ListPrice',
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
                                'image' => $coverImage,
                                'url' => $pageUrl . '#item-' . $item->id,
                                'brand' => ['@type' => 'Brand', 'name' => $item->brand?->name ?: $item->product_name],
                                'offers' => $offer,
                            ],
                        ];
                    })->all(),
                ],
            ];

            $unifiedSchema = [
                '@context' => 'https://schema.org',
                '@graph' => $graph,
            ];
        @endphp
        <script type="application/ld+json">{!! json_encode($unifiedSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
    @endpush

    <!-- Breadcrumb - Navy hover -->
    <nav class="mb-4 text-xs font-semibold text-slate-500" aria-label="Breadcrumb">
        <ol class="flex items-center gap-1.5">
            <li><a href="/" class="hover:text-[#039652]">الرئيسية</a></li>
            <li>/</li>
            <li><a href="{{ route('retailers.show', $flyer->retailer->slug) }}" class="hover:text-[#039652]">{{ $flyer->retailer->name }}</a></li>
            <li>/</li>
            <li class="text-[#023b55] truncate max-w-xs font-bold">{{ $cleanTitle }}</li>
        </ol>
    </nav>

    <!-- Flyer Header Card - Navy headings, Green validity -->
    <article class="mb-8 rounded-2xl border border-[#e2e8f0] bg-white p-6 shadow-sm">
        @if($flyer->isExpired())
            <div class="mb-6 rounded-2xl border-2 border-amber-500/30 bg-amber-50/80 p-5 text-amber-950 shadow-sm">
                <div class="flex items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-amber-500 text-white font-black text-lg">⚠️</span>
                    <div class="flex-1">
                        <h2 class="text-base font-black text-amber-900">تنبيه: هذا العرض انتهت فترة سريانه في {{ \Carbon\Carbon::parse($flyer->valid_until)->format('Y/m/d') }}</h2>
                        <p class="mt-0.5 text-xs text-amber-800">
                            الأسعار والخصومات الواردة أدناه محفوظة كأرشيف تاريخي لمقارنة تطور الأسعار في مصر.
                        </p>
                        @if($latestActive = $flyer->retailer->latestActiveFlyer())
                            <div class="mt-3 flex flex-wrap items-center justify-between gap-2 rounded-xl bg-white p-3 border border-amber-200">
                                <span class="text-xs font-bold text-slate-700">تتوفر الآن مجلة جديدة سارية لهذا المتجر:</span>
                                <a href="{{ route('flyers.show', $latestActive->slug) }}" class="inline-flex items-center gap-1 rounded-lg bg-[#039652] px-3.5 py-1.5 text-xs font-black text-white hover:bg-[#023b55] transition shadow-sm">
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

            @php
                $today = \Carbon\Carbon::today('Africa/Cairo');
                $from = \Carbon\Carbon::parse($flyer->valid_from, 'Africa/Cairo');
                $until = \Carbon\Carbon::parse($flyer->valid_until, 'Africa/Cairo');
            @endphp
            <div class="flex items-center gap-3">
                @if ($from->isFuture())
                    <span class="rounded-xl border border-[#fcc023]/50 bg-[#fcc023] px-3 py-1.5 text-xs font-bold text-slate-900">
                        يبدأ قريباً {{ $from->format('d/m/Y') }}
                    </span>
                    <span class="rounded-xl border border-[#023b55]/15 bg-white px-3 py-1.5 text-xs font-bold text-[#023b55]">
                        سارٍ من {{ $from->format('d/m/Y') }} حتى {{ $until->format('d/m/Y') }}
                    </span>
                @elseif ($until->isPast())
                    <span class="rounded-xl border border-slate-200 bg-slate-100 px-3 py-1.5 text-xs font-bold text-slate-600">
                        انتهى في {{ $until->format('d/m/Y') }}
                    </span>
                @else
                    <span class="rounded-xl border border-[#039652]/30 bg-[#039652]/10 px-3 py-1.5 text-xs font-bold text-[#039652]">
                        سارٍ حتى {{ $until->format('d/m/Y') }}
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

    <!-- عارض المجلة مصورة (Image Viewer - WebP from R2) - Desktop Optimized -->
    <section class="mb-12" id="flyer-viewer">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-lg font-black text-[#023b55]">تصفح صفحات المجلة ({{ $flyer->pages->count() }} صفحة)</h2>
            <span class="hidden text-xs text-slate-500 sm:inline">اضغط على أي صفحة للتكبير والتنقل</span>
        </div>

        {{-- Single focused column max-w-3xl for legibility: A4 vertical pages need full width on desktop --}}
        <div class="grid grid-cols-1 gap-4 max-w-3xl mx-auto">
            @foreach ($flyer->pages->sortBy('page_number') as $page)
                <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-100 bg-slate-50 px-4 py-2 text-xs font-bold text-slate-600">
                        صفحة رقم {{ $page->page_number }}
                    </div>
                    @php
                        $r2Cdn = rtrim(config('filesystems.disks.r2.url'), '/');
                        $heroImagePath = $page->image_path;
                        $fullImageUrl = $r2Cdn . '/' . ltrim($heroImagePath, '/');
                        $mobileOptimizedUrl = $r2Cdn . '/cdn-cgi/image/width=600,format=webp/' . ltrim($heroImagePath, '/');
                    @endphp
                    <button type="button" data-index="{{ $loop->index }}" aria-label="تكبير صفحة {{ $page->page_number }}" class="lightbox-trigger block w-full cursor-zoom-in focus:outline-none focus:ring-2 focus:ring-[#039652] focus:ring-offset-2">
                        <img
                            src="{{ $fullImageUrl }}"
                            srcset="{{ $fullImageUrl }} 1200w, {{ $mobileOptimizedUrl }} 600w"
                            sizes="(max-width: 640px) 100vw, 768px"
                            alt="{{ $cleanTitle }} - صفحة {{ $page->page_number }}"
                            width="1200"
                            height="1600"
                            @if ($loop->first) loading="eager" fetchpriority="high" decoding="async" @else loading="lazy" decoding="async" @endif
                            class="w-full h-auto object-contain"
                        >
                    </button>
                </div>
            @endforeach
        </div>
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

    @if (!empty($flyer->editorial_overview))
    <!-- Editorial Overview — Auto-linked contextual internal links -->
    <section class="mb-6 rounded-2xl border border-[#023b55]/10 bg-white p-5 sm:p-6 shadow-sm">
        <h2 class="mb-3 text-lg font-black text-[#023b55]">نظرة سريعة على العرض</h2>
        @php
            $cleanEditorial = trim((string) $flyer->editorial_overview);
            $cleanEditorial = (string) preg_replace("/[ \t]+\n/u", "\n", $cleanEditorial);
            $cleanEditorial = (string) preg_replace("/\n{3,}/u", "\n\n", $cleanEditorial);
            $cleanEditorial = (string) preg_replace("/:\n\n-/u", ":\n- ", $cleanEditorial);
        @endphp
        <div class="prose prose-sm max-w-none text-[13px] leading-6 text-slate-700 prose-headings:text-[#023b55] prose-strong:text-[#023b55] prose-p:my-2 prose-ul:my-2 prose-li:my-1 prose-a:text-[#039652] prose-a:font-bold">
            {!! app(\App\Services\AutoInternalLinkerService::class)->linkify($cleanEditorial, $flyer) !!}
        </div>
    </section>
    @endif

    <style>#flyer-lightbox:not(.hidden){display:flex}#flyer-lightbox.hidden{display:none} html{scroll-behavior:smooth} tr:target, tr.highlight-target{background-color:rgba(3,150,82,0.10)!important; transition:background-color .3s ease}</style>
    <script>
    // Deep-link highlight for #item-{id} from hot deals
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
        const pages = @json($flyer->pages->sortBy('page_number')->values()->map(fn($p) => ['src' => \App\Support\R2Url::asset($p->image_path), 'alt' => $cleanTitle.' - صفحة '.$p->page_number]));
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

    <!-- جدول الأسعار والسلع المكتشفة في المجلة (Structured Items Table) - Navy/Green -->
    <section class="rounded-2xl border border-[#e2e8f0] bg-white p-6 shadow-sm">
        <h2 class="mb-2 text-lg font-black text-[#023b55]">جدول السلع والأسعار المفصلة في هذا العرض</h2>
        <p class="mb-6 text-xs text-slate-500">تم استخراج وقراءة هذه الأسعار تلقائياً وتدقيقها لضمان سهولة المقارنة والبحث.</p>

        <div class="overflow-x-auto">
            <table class="w-full text-right text-xs">
                <thead class="border-b border-[#023b55]/10 bg-[#023b55]/5 text-[#023b55]">
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
                        <tr id="item-{{ $item->id }}" class="hover:bg-[#039652]/5">
                            <td class="p-3 font-bold text-[#023b55]">
                                <div>{{ $item->product_name }}</div>
                                @php $compareQuery = $item->brand?->name ?: \Illuminate\Support\Str::words($item->product_name, 2, ''); $compareQuery = trim((string) $compareQuery); @endphp
                                @if ($compareQuery !== '')
                                    <a href="{{ route('home', ['q' => $compareQuery]) }}" rel="nofollow" class="mt-1 inline-flex items-center gap-1 rounded-full border border-[#039652]/20 bg-[#039652]/10 px-2 py-0.5 text-[10px] font-bold text-[#039652] hover:bg-[#039652] hover:text-white transition">قارن الأسعار 🔍</a>
                                @endif
                            </td>
                            <td class="p-3 text-slate-500">{{ $item->brand?->name ?: '—' }}</td>
                            <td class="p-3 text-slate-500">{{ $item->unit ?: 'قطعة' }}</td>
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
                                    <span class="rounded px-2 py-0.5 text-[11px] font-bold {{ $isSuper ? 'bg-[#fcc023]/20 text-slate-900 border border-[#fcc023]/30' : 'bg-[#039652]/10 text-[#039652]' }}">
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

    <!-- Dynamic Cross-Linking Widgets -->
    @if (isset($sameRetailerFlyers) && $sameRetailerFlyers->isNotEmpty())
    <section class="mt-10 rounded-2xl border border-[#023b55]/10 bg-white p-6 shadow-sm">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-lg font-black text-[#023b55]">عروض أخرى سارية اليوم من {{ $flyer->retailer->name }}</h2>
            <a href="{{ route('retailers.show', $flyer->retailer->slug) }}" class="text-xs font-bold text-[#039652] hover:underline">عرض الكل ←</a>
        </div>
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
            @foreach ($sameRetailerFlyers as $related)
                @php $cov = $related->pages->first(); $u = $cov ? \App\Support\R2Url::asset($cov->image_path) : '/img/placeholder-flyer.png'; @endphp
                <a href="{{ route('flyers.show', $related->slug) }}" class="group flex flex-col overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm transition hover:border-[#039652] hover:shadow-md">
                    <div class="relative aspect-[3/4] overflow-hidden bg-slate-100">
                        <img src="{{ $u }}" alt="{{ $related->title }}" width="400" height="530" loading="lazy" class="h-full w-full object-cover transition duration-300 group-hover:scale-[1.03]">
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
                @php $cov = $comp->pages->first(); $u = $cov ? \App\Support\R2Url::asset($cov->image_path) : '/img/placeholder-flyer.png'; @endphp
                <a href="{{ route('flyers.show', $comp->slug) }}" class="group flex flex-col overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm transition hover:border-[#039652] hover:shadow-md">
                    <div class="relative aspect-[3/4] overflow-hidden bg-slate-100">
                        <img src="{{ $u }}" alt="{{ $comp->title }}" width="400" height="530" loading="lazy" class="h-full w-full object-cover transition duration-300 group-hover:scale-[1.03]">
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
            <a href="{{ route('home') }}" class="inline-flex rounded-xl border border-[#023b55]/15 bg-[#023b55]/5 px-4 py-2 text-xs font-bold text-[#023b55] hover:bg-[#039652] hover:text-white hover:border-[#039652] transition">تصفح كل العروض السارية →</a>
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
                @php $cov = $arc->pages->first(); $u = $cov ? \App\Support\R2Url::asset($cov->image_path) : '/img/placeholder-flyer.png'; @endphp
                <a href="{{ route('flyers.show', $arc->slug) }}" class="flex flex-col overflow-hidden rounded-xl border border-slate-200 bg-white opacity-95 hover:opacity-100 hover:border-[#023b55]/20 transition">
                    <div class="relative aspect-[3/4] overflow-hidden bg-slate-100">
                        <img src="{{ $u }}" alt="{{ $arc->title }}" width="400" height="530" loading="lazy" class="h-full w-full object-cover grayscale hover:grayscale-0 transition">
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
</x-layouts.app>
