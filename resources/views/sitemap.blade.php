{!! '<'.'?xml version="1.0" encoding="UTF-8"?'.'>' !!}
@php
    // Strip invalid XML control chars BEFORE escaping — htmlspecialchars does
    // NOT remove them and a single \x0B in a title makes strict XML parsers
    // reject the whole document.
    $xmlClean = static function ($value): string {
        return htmlspecialchars(
            (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', (string) $value),
            ENT_XML1 | ENT_QUOTES,
            'UTF-8',
        );
    };
    // Canonical host from config — never the request host (CLI/admin leaks).
    $baseUrl = rtrim((string) ($baseUrl ?? config('app.url')), '/');
@endphp
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">
    <!-- Homepage -->
    <url>
        <loc>{{ $baseUrl }}/</loc>
        <lastmod>{{ $homepageLastmod }}</lastmod>
        <changefreq>hourly</changefreq>
        <priority>1.0</priority>
    </url>

    <!-- Canonical Retailer Hubs -->
    @foreach ($retailers as $retailer)
        @php
            // lastmod = max(retailer.updated_at, latest PUBLISHED flyer
            // updated_at). Both are 'Y-m-d H:i:s' strings, so a string max()
            // is chronological-safe. A retailer with no published flyers
            // falls back to its own updated_at ('' loses the max()).
            $retailerLastmod = max(
                (string) ($retailer->getRawOriginal('updated_at') ?? $retailer->updated_at),
                (string) ($retailer->latest_flyer_updated_at ?? ''),
            );
        @endphp
        <url>
            <loc>{{ $baseUrl.route('retailers.show', ['retailer' => $retailer->slug], false) }}</loc>
            <lastmod>{{ \App\Support\CairoTime::toIso8601($retailerLastmod) }}</lastmod>
            <changefreq>daily</changefreq>
            <priority>0.8</priority>
            @if ($retailer->logo_path)
                <image:image>
                    <image:loc>{{ \App\Support\R2Url::asset($retailer->logo_path) }}</image:loc>
                    <image:title>{!! $xmlClean($retailer->name) !!}</image:title>
                </image:image>
            @endif
        </url>
    @endforeach

    <!-- Active Published Flyers (expired pages render noindex — NEVER listed here) -->
    @foreach ($activeFlyers ?? $flyers ?? [] as $flyer)
        <url>
            <loc>{{ $baseUrl.route('flyers.show', ['slug' => $flyer->slug], false) }}</loc>
            <lastmod>{{ \App\Support\CairoTime::toIso8601($flyer->getRawOriginal('updated_at') ?? $flyer->updated_at) }}</lastmod>
            <changefreq>daily</changefreq>
            <priority>0.9</priority>
            @foreach ($flyer->pages->sortBy('page_number')->take(5) as $page)
                <image:image>
                    <image:loc>{{ \App\Support\R2Url::asset($page->image_path) }}</image:loc>
                    <image:title>{!! $xmlClean($flyer->title . ' - صفحة ' . $page->page_number) !!}</image:title>
                    <image:caption>{!! $xmlClean('أسعار وتخفيضات ' . (string) $flyer->retailer->name . ' في مصر - ' . (string) $flyer->title) !!}</image:caption>
                </image:image>
            @endforeach
        </url>
    @endforeach
</urlset>
