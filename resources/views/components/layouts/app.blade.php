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
    <meta name="theme-color" content="#0284c7">

    @php
        $siteName = config('app.name', 'عروض نت مصر');
        $canonicalUrl = url()->current();
        $r2Url = (string) config('filesystems.disks.r2.url');
        $r2Host = parse_url($r2Url, PHP_URL_HOST);
    @endphp

    <title>{{ $metaTitle ? $metaTitle . ' | ' . $siteName : $siteName . ' | عروض وتخفيضات السوبرماركت في مصر اليوم' }}</title>
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
    <script type="application/ld+json">{!! json_encode($organizationSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        body { font-family: 'Readex Pro', system-ui, sans-serif; }
    </style>

    @stack('head_meta')
    @stack('schema')
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 antialiased selection:bg-sky-600 selection:text-white">

    <!-- Header -->
    <header class="sticky top-0 z-40 border-b border-slate-200 bg-white/95 backdrop-blur">
        <div class="mx-auto flex max-w-7xl items-center justify-between px-4 py-3 sm:px-6">
            <a href="/" class="flex items-center gap-2 no-underline">
                <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-sky-600 text-white font-black text-xl shadow-md shadow-sky-600/20">ع</span>
                <span class="text-xl font-black text-slate-900">{{ config('app.name', 'عروض نت') }}</span>
            </a>

            <!-- نموذج البحث السريع -->
            <form action="{{ route('home') }}" method="GET" class="relative hidden sm:block w-72 lg:w-96">
                <input
                    type="search"
                    name="q"
                    value="{{ request('q') }}"
                    placeholder="ابحث عن سلعة (أرز، زيت، جبنة، كارفور...)"
                    class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2 pr-10 text-xs font-medium focus:border-sky-500 focus:bg-white focus:outline-none"
                >
                <button type="submit" class="absolute right-3 top-2.5 text-slate-400 hover:text-sky-600">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                </button>
            </form>

            <nav class="flex items-center gap-3 text-xs font-bold text-slate-600">
                <a href="{{ route('home') }}" class="hover:text-sky-600">الرئيسية</a>
                <a href="{{ route('home', ['type' => 'magazines']) }}" class="hover:text-sky-600">المجلات الكاملة</a>
            </nav>
        </div>
    </header>

    <!-- Main Content -->
    <main class="mx-auto max-w-7xl px-4 py-6 sm:px-6">
        {{ $slot }}
    </main>

    <!-- Footer -->
    <footer class="mt-16 border-t border-slate-200 bg-white py-10 text-center text-xs text-slate-500">
        <div class="mx-auto max-w-7xl px-4 sm:px-6">
            <p class="font-bold text-slate-700">جميع الأسعار والعروض تخضع لشروط السلاسل التجارية المعلنة وتاريخ سريانها في مصر.</p>
            <p class="mt-1">العلامات التجارية والشعارات ملك لأصحابها وناشريها الرسميين.</p>
            <p class="mt-4">© {{ date('Y') }} {{ config('app.name', 'عروض نت') }} — منصة متابعة أسعار وتخفيضات السوبرماركت في مصر.</p>
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
