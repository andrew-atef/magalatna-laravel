{!! '<'.'?xml version="1.0" encoding="UTF-8"?'.'>' !!}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">
    <!-- Homepage -->
    <url>
        <loc>{{ url('/') }}</loc>
        <lastmod>{{ now('Africa/Cairo')->toIso8601String() }}</lastmod>
        <changefreq>hourly</changefreq>
        <priority>1.0</priority>
    </url>

    <!-- Canonical Retailer Hubs -->
    @foreach ($retailers as $retailer)
        <url>
            <loc>{{ route('retailers.show', $retailer->slug) }}</loc>
            <lastmod>{{ $retailer->updated_at->copy()->setTimezone('Africa/Cairo')->toIso8601String() }}</lastmod>
            <changefreq>daily</changefreq>
            <priority>0.8</priority>
            @if ($retailer->logo_path)
                <image:image>
                    <image:loc>{{ rtrim((string) config('filesystems.disks.r2.url'), '/') . '/' . ltrim((string) $retailer->logo_path, '/') }}</image:loc>
                    <image:title>{{ $retailer->name }}</image:title>
                </image:image>
            @endif
        </url>
    @endforeach

    <!-- Active Published Flyers -->
    @foreach ($activeFlyers ?? $flyers ?? [] as $flyer)
        <url>
            <loc>{{ route('flyers.show', $flyer->slug) }}</loc>
            <lastmod>{{ $flyer->updated_at->copy()->setTimezone('Africa/Cairo')->toIso8601String() }}</lastmod>
            <changefreq>daily</changefreq>
            <priority>0.9</priority>
            @foreach ($flyer->pages->sortBy('page_number') as $page)
                <image:image>
                    <image:loc>{{ rtrim((string) config('filesystems.disks.r2.url'), '/') . '/' . ltrim((string) $page->image_path, '/') }}</image:loc>
                    <image:title>{{ $flyer->title }} - صفحة {{ $page->page_number }}</image:title>
                    <image:caption>أسعار وتخفيضات {{ $flyer->retailer->name }} في مصر - {{ $flyer->title }}</image:caption>
                </image:image>
            @endforeach
        </url>
    @endforeach

    <!-- Recently Expired Flyers (Historical Archive) -->
    @foreach ($expiredFlyers ?? [] as $flyer)
        <url>
            <loc>{{ route('flyers.show', $flyer->slug) }}</loc>
            <lastmod>{{ $flyer->updated_at->copy()->setTimezone('Africa/Cairo')->toIso8601String() }}</lastmod>
            <changefreq>never</changefreq>
            <priority>0.3</priority>
        </url>
    @endforeach
</urlset>
