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
    <link rel="alternate icon" href="/favicon.ico">
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

    <!-- Google Fonts: Readex Pro Preloaded لمنع الـ CLS -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Readex+Pro:wght@300;400;500;600;700;800&display=optional">
    <link href="https://fonts.googleapis.com/css2?family=Readex+Pro:wght@300;400;500;600;700;800&display=optional" rel="stylesheet" media="print" onload="this.media='all'">

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

    <!-- Header - Deep Petrol Navy structure -->
    <header class="sticky top-0 z-40 border-b-2 border-[#023b55]/10 bg-white/95 backdrop-blur">
        <div class="mx-auto flex max-w-7xl items-center justify-between px-4 py-3 sm:px-6">
            <a href="/" class="flex items-center gap-2 no-underline" aria-label="{{ config('app.name', 'مجلاتنا') }} - الرئيسية">
                <img src="/logo.webp" alt="مجلاتنا - عروض وتخفيضات السوبرماركت في مصر" width="140" height="36" class="h-9 w-auto object-contain" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                <span class="hidden h-9 w-9 items-center justify-center rounded-xl bg-[#023b55] text-white font-black text-xl shadow-md shadow-[#023b55]/20 border border-[#039652]/30">ع</span>
                <span class="sr-only">{{ config('app.name', 'مجلاتنا') }}</span>
            </a>

            <!-- نموذج البحث السريع -->
            <form action="{{ route('home') }}" method="GET" class="relative hidden sm:block w-72 lg:w-96">
                <input
                    type="search"
                    name="q"
                    value="{{ request('q') }}"
                    placeholder="ابحث عن سلعة (أرز، زيت، جبنة، كارفور...)"
                    class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2 pr-10 text-xs font-medium text-[#023b55] placeholder:text-slate-400 focus:border-[#039652] focus:bg-white focus:outline-none focus:ring-2 focus:ring-[#039652]/20"
                >
                <button type="submit" class="absolute right-3 top-2.5 text-slate-400 hover:text-[#039652] transition-colors">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                </button>
            </form>

            <nav class="flex items-center gap-3 text-xs font-bold text-[#023b55]">
                <a href="{{ route('home') }}" class="hover:text-[#039652] transition-colors">الرئيسية</a>
                <a href="{{ route('home', ['type' => 'magazines']) }}" class="hover:text-[#039652] transition-colors">المجلات الكاملة</a>
            </nav>
        </div>
    </header>

    <!-- Main Content -->
    <main class="mx-auto max-w-7xl px-4 py-6 sm:px-6">
        {{ $slot }}
    </main>

    <!-- Footer - Deep Petrol Navy -->
    <footer class="mt-16 border-t-2 border-[#023b55]/15 bg-[#023b55] py-10 text-center text-xs">
        <div class="mx-auto max-w-7xl px-4 sm:px-6">
            <p class="font-bold text-white">جميع الأسعار والعروض تخضع لشروط السلاسل التجارية المعلنة وتاريخ سريانها في مصر.</p>
            <p class="mt-1 text-slate-300">العلامات التجارية والشعارات ملك لأصحابها وناشريها الرسميين.</p>
            <p class="mt-4 text-[#fcc023]/90">© {{ date('Y') }} {{ config('app.name', 'مجلاتنا') }} — منصة متابعة أسعار وتخفيضات السوبرماركت في مصر.</p>
        </div>
    </footer>

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
