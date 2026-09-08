{!! '<'.'?xml version="1.0" encoding="UTF-8"?'.'>' !!}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <!-- الصفحة الرئيسية -->
    <url>
        <loc>{{ url('/') }}</loc>
        <lastmod>{{ now()->toAtomString() }}</lastmod>
        <changefreq>daily</changefreq>
        <priority>1.0</priority>
    </url>

    <!-- صفحات المتاجر (Retailer Hubs) -->
    @foreach ($retailers as $retailer)
        <url>
            <loc>{{ route('retailers.show', $retailer->slug) }}</loc>
            <lastmod>{{ $retailer->updated_at->toAtomString() }}</lastmod>
            <changefreq>daily</changefreq>
            <priority>0.8</priority>
        </url>
    @endforeach

    <!-- صفحات مجلات العروض السارية -->
    @foreach ($flyers as $flyer)
        <url>
            <loc>{{ route('flyers.show', $flyer->slug) }}</loc>
            <lastmod>{{ $flyer->updated_at->toAtomString() }}</lastmod>
            <changefreq>weekly</changefreq>
            <priority>{{ \Carbon\Carbon::parse($flyer->valid_until)->isFuture() ? '0.9' : '0.4' }}</priority>
        </url>
    @endforeach
</urlset>
