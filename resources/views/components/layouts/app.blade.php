@props([
    'metaTitle' => null,
    'metaDescription' => null,
    'ogTitle' => null,
    'ogDescription' => null,
    'ogImage' => null,
    'ogType' => 'website',
    'robots' => 'index, follow, max-image-size:large, max-snippet:-1, max-video-preview:-1',
])

<!DOCTYPE html>
<html lang="ar" dir="rtl" class="scroll-smooth overflow-x-hidden">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <meta name="theme-color" content="#023b55">

    @php
        $siteName = config('app.name', 'مجلاتنا');
        $canonicalUrl = url()->current();
        $r2Url = (string) config('filesystems.disks.r2.url');
        $r2Host = parse_url($r2Url, PHP_URL_HOST);
    @endphp

    <title>{{ $metaTitle ? $metaTitle . ' | ' . $siteName : $siteName . ' | مجلات وعروض السوبرماركت في مصر اليوم' }}</title>
    <meta name="description" content="{{ $metaDescription ?? 'تصفح أحدث مجلات وعروض كارفور، كازيون، بيم، هايبر وان، وفتح الله اليوم في مصر. قارن أسعار السلع قبل الشراء ووفر ميزانيتك.' }}">
    <meta name="robots" content="{{ $robots }}">

    <link rel="preload" href="/fonts/readex-pro.woff2" as="font" type="font/woff2" crossorigin>

    <!-- R2 CDN Preconnect -->
    @if ($r2Host)
        <link rel="preconnect" href="https://{{ $r2Host }}">
        <link rel="dns-prefetch" href="https://{{ $r2Host }}">
    @endif

    <!-- OpenGraph & Twitter -->
    <meta property="og:type" content="{{ $ogType }}">
    <meta property="og:site_name" content="{{ $siteName }}">
    <meta property="og:title" content="{{ $ogTitle ?? ($metaTitle ?? $siteName) }}">
    <meta property="og:description" content="{{ $ogDescription ?? ($metaDescription ?? '') }}">
    <meta property="og:image" content="{{ $ogImage ?: url('/img/og-cover.png') }}">
    <meta property="og:url" content="{{ $canonicalUrl }}">

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $ogTitle ?? ($metaTitle ?? $siteName) }}">
    <meta name="twitter:description" content="{{ $ogDescription ?? ($metaDescription ?? '') }}">
    <meta name="twitter:image" content="{{ $ogImage ?: url('/img/og-cover.png') }}">

    <link rel="canonical" href="{{ $canonicalUrl }}">

    @php
        $organizationSchema = [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => $siteName,
            'url' => url('/'),
            'logo' => url('/favicon.svg'),
            'areaServed' => 'EG',
            'knowsAbout' => [
                'عروض كارفور مصر',
                'تخفيضات كازيون',
                'عروض بيم الأسبوعية',
                'أسعار السلع في مصر',
            ],
        ];
    @endphp
    @if (! request()->routeIs('flyers.show'))
        <script type="application/ld+json">{!! json_encode($organizationSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
    @endif

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        body { font-family: 'Readex Pro', system-ui, sans-serif; }
    </style>

    @stack('head_meta')
    @stack('schema')
</head>
<body class="min-h-screen bg-[#f8fafc] text-slate-900 antialiased selection:bg-[#039652] selection:text-white">

    @php
        $headerRetailers = \App\Models\Retailer::where('is_active', true)->orderBy('name')->limit(12)->get();
    @endphp
    <!-- Header - Deep Petrol Navy / Green system - Desktop + Mobile Search/Menu -->
    <header class="sticky top-0 z-40 border-b-2 border-[#023b55]/10 bg-white/95 backdrop-blur">
        <div class="mx-auto flex max-w-7xl items-center justify-between gap-2 px-4 py-3 sm:px-6">
            <!-- Logo & Brand (always visible - right in RTL) -->
            <a href="{{ route('home') }}" class="flex items-center gap-2 no-underline shrink-0" aria-label="{{ config('app.name', 'مجلاتنا') }} - الرئيسية">
                <img src="/logo.webp" alt="مجلاتنا - عروض وتخفيضات السوبرماركت في مصر" width="140" height="36" class="h-9 w-auto object-contain" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                <span class="hidden h-9 w-9 items-center justify-center rounded-xl bg-[#023b55] text-white font-black text-xl shadow-md shadow-[#023b55]/20 border border-[#039652]/30">ع</span>
                <span class="sr-only">{{ config('app.name', 'مجلاتنا') }}</span>
            </a>

            <!-- Desktop Navigation (lg+) -->
            <nav class="hidden lg:flex items-center gap-3 xl:gap-4 text-xs font-bold text-[#023b55]">
                <a href="{{ route('home') }}" class="hover:text-[#039652] transition-colors {{ request()->routeIs('home') && !request()->has('q') && !request()->has('type') ? 'text-[#039652]' : '' }}">الرئيسية</a>
                @php $kazyon = $headerRetailers->firstWhere('slug','kazyon'); @endphp
                <a href="{{ $kazyon ? route('retailers.show', $kazyon->slug) : route('retailers.show','kazyon') }}" class="hover:text-[#039652] transition-colors">كازيون</a>
                @php $carrefour = $headerRetailers->firstWhere('slug','carrefouregypt') ?? $headerRetailers->firstWhere('slug','carrefour'); @endphp
                <a href="{{ $carrefour ? route('retailers.show', $carrefour->slug) : route('retailers.show','carrefouregypt') }}" class="hover:text-[#039652] transition-colors">كارفور</a>
                @php $bim = $headerRetailers->firstWhere('slug','bimmisr') ?? $headerRetailers->firstWhere('slug','bim'); @endphp
                <a href="{{ $bim ? route('retailers.show', $bim->slug) : route('retailers.show','bimmisr') }}" class="hover:text-[#039652] transition-colors">بيم</a>
                <a href="{{ route('home', ['q' => 'اليوم الواحد']) }}" class="hover:text-[#039652] transition-colors">عروض اليوم الواحد</a>
                <a href="{{ route('home', ['type' => 'magazines']) }}" class="hover:text-[#039652] transition-colors">جميع المجلات</a>
            </nav>

            <!-- Desktop Search Bar (lg+) -->
            <form action="{{ route('home') }}" method="GET" class="relative hidden lg:block w-72 xl:w-96 shrink-0">
                <input
                    type="search"
                    name="q"
                    value="{{ request('q') }}"
                    placeholder="ابحث عن سلعة (أرز، سكر، زيت، جبنة...)"
                    class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2 pr-10 text-xs font-medium text-[#023b55] placeholder:text-slate-400 focus:border-[#039652] focus:bg-white focus:outline-none focus:ring-2 focus:ring-[#039652]/20"
                >
                <button type="submit" class="absolute right-3 top-2.5 text-slate-400 hover:text-[#039652] transition-colors" aria-label="بحث">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                </button>
            </form>

            <!-- Mobile Action Icons ( < lg ) - Left side in RTL -->
            <div class="flex items-center gap-1.5 lg:hidden shrink-0">
                <button id="mobile-search-trigger" type="button" aria-label="البحث السريع" class="inline-flex h-9 w-9 items-center justify-center rounded-xl border border-slate-200 bg-white text-[#023b55] hover:border-[#039652]/30 hover:text-[#039652] transition">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                </button>
                <button id="mobile-menu-trigger" type="button" aria-label="قائمة المتاجر" class="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-[#023b55] text-white hover:bg-[#039652] transition shadow-sm">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
            </div>
        </div>

        <!-- Mobile Search Overlay (instant full-width) -->
        <div id="mobile-search" class="hidden border-t border-slate-200 bg-white px-4 py-3 lg:hidden">
            <form action="{{ route('home') }}" method="GET" class="relative">
                <input
                    id="mobile-search-input"
                    type="search"
                    name="q"
                    value="{{ request('q') }}"
                    placeholder="ابحث عن سلعة (أرز، سكر، زيت، جبنة...)"
                    class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 pr-10 text-sm font-medium text-[#023b55] placeholder:text-slate-400 focus:border-[#039652] focus:bg-white focus:outline-none focus:ring-2 focus:ring-[#039652]/20"
                >
                <button type="submit" class="absolute right-3 top-3 text-slate-400 hover:text-[#039652] transition-colors" aria-label="بحث">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                </button>
            </form>
        </div>
    </header>

    <!-- Mobile Drawer - Stores List -->
    <div id="mobile-drawer" class="fixed inset-0 z-50 hidden" aria-hidden="true">
        <div id="mobile-drawer-backdrop" class="absolute inset-0 bg-black/50 backdrop-blur-sm"></div>
        <div class="absolute right-0 top-0 h-full w-80 max-w-[85vw] bg-white shadow-2xl flex flex-col">
            <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                <span class="text-sm font-black text-[#023b55]">المتاجر والتصنيفات</span>
                <button id="mobile-drawer-close" type="button" aria-label="إغلاق" class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50 hover:text-[#023b55] transition">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="flex-1 overflow-y-auto px-2 py-3">
                <nav class="space-y-1">
                    <a href="{{ route('home') }}" class="flex items-center gap-2 rounded-xl px-3 py-2.5 text-sm font-bold text-[#023b55] hover:bg-[#039652]/10 hover:text-[#039652] transition">الرئيسية</a>
                    <a href="{{ route('home', ['type' => 'magazines']) }}" class="flex items-center gap-2 rounded-xl px-3 py-2.5 text-sm font-bold text-[#023b55] hover:bg-[#039652]/10 hover:text-[#039652] transition">جميع المجلات</a>
                    <a href="{{ route('home', ['q' => 'اليوم الواحد']) }}" class="flex items-center gap-2 rounded-xl px-3 py-2.5 text-sm font-bold text-[#023b55] hover:bg-[#039652]/10 hover:text-[#039652] transition">عروض اليوم الواحد</a>
                    <div class="my-2 border-t border-slate-100"></div>
                    <p class="px-3 py-1 text-xs font-black text-slate-400">المتاجر</p>
                    @foreach ($headerRetailers as $navRet)
                        <a href="{{ route('retailers.show', $navRet->slug) }}" class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-bold text-[#023b55] hover:bg-slate-50 hover:text-[#039652] transition">
                            @if ($navRet->logo_path)
                                <img src="{{ \App\Support\R2Url::asset($navRet->logo_path) }}" alt="{{ $navRet->name }}" class="h-6 w-6 rounded-full object-contain border border-slate-200 bg-white">
                            @else
                                <span class="flex h-6 w-6 items-center justify-center rounded-full bg-[#023b55] text-[10px] font-black text-white">{{ mb_substr($navRet->name,0,1) }}</span>
                            @endif
                            <span>{{ $navRet->name }}</span>
                        </a>
                    @endforeach
                </nav>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <main class="mx-auto max-w-7xl px-4 py-6 sm:px-6 pb-20 lg:pb-6">
        {{ $slot }}
    </main>

    <!-- Footer - Deep Petrol Navy -->
    <footer class="mt-16 border-t-2 border-[#023b55]/15 bg-[#023b55] py-10 text-center text-xs">
        <div class="mx-auto max-w-7xl px-4 sm:px-6">
            <p class="font-bold text-white">جميع الأسعار والعروض تخضع لشروط السلاسل التجارية المعلنة وتاريخ سريانها في مصر.</p>
            <p class="mt-1 text-slate-300">العلامات التجارية والشعارات ملك لأصحابها وناشريها الرسميين.</p>
            <div class="flex flex-wrap justify-center gap-4 text-xs text-slate-300 mt-4">
                <a href="{{ route('pages.about') }}" class="hover:text-white transition">من نحن</a>
                <span>•</span>
                <a href="{{ route('pages.privacy') }}" class="hover:text-white transition">سياسة الخصوصية</a>
                <span>•</span>
                <a href="{{ route('pages.terms') }}" class="hover:text-white transition">شروط الاستخدام</a>
                <span>•</span>
                <a href="{{ route('pages.contact') }}" class="hover:text-white transition">اتصل بنا</a>
            </div>
            <p class="mt-4 text-[#fcc023]/90">© {{ date('Y') }} {{ config('app.name', 'مجلاتنا') }} — منصة متابعة أسعار وتخفيضات السوبرماركت في مصر.</p>
        </div>
    </footer>

    <!-- Mobile Floating Bottom Bar (Sticky Thumb Navigation) -->
    <nav class="fixed inset-x-0 bottom-0 z-30 flex items-center justify-around gap-1 border-t border-slate-200 bg-white/95 px-2 py-2 pb-[calc(0.5rem+env(safe-area-inset-bottom))] backdrop-blur shadow-[0_-2px_10px_rgba(0,0,0,0.06)] lg:hidden" aria-label="التنقل السريع">
        <a href="{{ route('home') }}" class="flex flex-1 flex-col items-center gap-0.5 rounded-xl py-1 text-[11px] font-bold text-[#023b55] hover:bg-slate-50 hover:text-[#039652] transition">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
            <span>الرئيسية</span>
        </a>
        <button type="button" id="bottom-bar-stores" class="flex flex-1 flex-col items-center gap-0.5 rounded-xl py-1 text-[11px] font-bold text-[#023b55] hover:bg-slate-50 hover:text-[#039652] transition" aria-label="المتاجر">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
            <span>المتاجر</span>
        </button>
        <button type="button" id="bottom-bar-search" class="flex flex-1 flex-col items-center gap-0.5 rounded-xl py-1 text-[11px] font-bold text-[#023b55] hover:bg-slate-50 hover:text-[#039652] transition" aria-label="البحث السريع">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            <span>البحث</span>
        </button>
        <a href="{{ request()->routeIs('home') ? '#hot-deals' : route('home').'#hot-deals' }}" class="flex flex-1 flex-col items-center gap-0.5 rounded-xl py-1 text-[11px] font-bold text-[#039652] hover:bg-[#039652]/10 transition">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
            <span>أقوى الخصومات</span>
        </a>
    </nav>

    <!-- Lightweight inline JS for mobile header interactions (no heavy libs) -->
    <script>
    (function(){
        const searchBtn = document.getElementById('mobile-search-trigger');
        const searchPanel = document.getElementById('mobile-search');
        const searchInput = document.getElementById('mobile-search-input');
        const menuBtn = document.getElementById('mobile-menu-trigger');
        const drawer = document.getElementById('mobile-drawer');
        const drawerClose = document.getElementById('mobile-drawer-close');
        const drawerBackdrop = document.getElementById('mobile-drawer-backdrop');
        const bottomStores = document.getElementById('bottom-bar-stores');
        const bottomSearch = document.getElementById('bottom-bar-search');
        function toggleSearch(){
            if(!searchPanel) return;
            searchPanel.classList.toggle('hidden');
            if(!searchPanel.classList.contains('hidden') && searchInput){
                searchInput.focus();
                searchInput.scrollIntoView({behavior:'smooth', block:'center'});
            }
        }
        function openDrawer(){
            if(!drawer) return;
            drawer.classList.remove('hidden');
            drawer.setAttribute('aria-hidden','false');
            document.body.style.overflow='hidden';
        }
        function closeDrawer(){
            if(!drawer) return;
            drawer.classList.add('hidden');
            drawer.setAttribute('aria-hidden','true');
            document.body.style.overflow='';
        }
        if(searchBtn) searchBtn.addEventListener('click', toggleSearch);
        if(bottomSearch) bottomSearch.addEventListener('click', toggleSearch);
        if(menuBtn) menuBtn.addEventListener('click', openDrawer);
        if(bottomStores) bottomStores.addEventListener('click', openDrawer);
        if(drawerClose) drawerClose.addEventListener('click', closeDrawer);
        if(drawerBackdrop) drawerBackdrop.addEventListener('click', closeDrawer);
        document.addEventListener('keydown', function(e){ if(e.key==='Escape'){ closeDrawer(); if(searchPanel && !searchPanel.classList.contains('hidden')) searchPanel.classList.add('hidden'); }});
    })();
    </script>

    <!-- WebMCP Tool Registration لمحركات الذكاء الاصطناعي والمتصفحات الذكية -->
    <script>
    (function () {
        if (typeof window !== 'undefined' && window.navigator && 'modelContext' in navigator && typeof navigator.modelContext.registerTool === 'function') {
            try {
                navigator.modelContext.registerTool({
                    name: "search_egypt_supermarket_offers",
                    description: "Search active Egyptian supermarket flyers and daily grocery discounts (Carrefour, Kazyon, BIM, HyperOne).",
                    inputSchema: {
                        type: "object",
                        properties: {
                            query: { type: "string", description: "Product name e.g. 'زيت عباد الشمس' or supermarket name 'كازيون'" }
                        },
                        required: ["query"]
                    }
                });
            } catch (e) {}
        }
    })();
    </script>
</body>
</html>
