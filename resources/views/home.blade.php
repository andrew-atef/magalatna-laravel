@php
    use App\Support\R2Url;
    $siteName = config('app.name', 'عروض نت');
    $searchQuery = request('q', '');
    $selectedRetailerSlug = request('retailer');
    $r2DiskUrl = R2Url::base();
@endphp

<x-layouts.app
    :meta-title="$searchQuery ? 'نتائج البحث عن ' . $searchQuery : 'أحدث عروض ومجلات السوبرماركت في مصر اليوم'"
    :meta-description="'تصفح أحدث عروض وتخفيضات سلاسل السوبرماركت في مصر (كازيون، كارفور، بيم، فتح الله). أسعار السلع والجبن والزيوت محدثة ومفهرسة يومياً.'"
>
    @push('schema')
        @php
            $websiteSchema = [
                '@context' => 'https://schema.org',
                '@type' => 'WebSite',
                'name' => $siteName,
                'url' => url('/'),
                'potentialAction' => [
                    '@type' => 'SearchAction',
                    'target' => [
                        '@type' => 'EntryPoint',
                        'urlTemplate' => url('/') . '/?q={search_term_string}',
                    ],
                    'query-input' => 'required name=search_term_string',
                ],
            ];
        @endphp
        <script type="application/ld+json">{!! json_encode($websiteSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
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
                href="{{ route('home') }}"
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
                        <img src="{{ R2Url::asset($ret->logo_path) }}" alt="{{ $ret->name }}" class="h-4 w-4 rounded-full object-contain">
                    @endif
                    <span>{{ $ret->name }}</span>
                </a>
            @endforeach
        </div>
    </section>

    <!-- المجلات السارية حالياً (Active Flyers Grid) - Navy headings -->
    <section class="mb-12">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-lg font-black text-[#023b55] sm:text-xl">أحدث مجلات العروض السارية</h2>
            <span class="text-xs font-semibold text-slate-500">{{ $flyers->total() }} مجلة سارية</span>
        </div>

        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
            @forelse ($flyers as $flyer)
                @php
                    $coverPage = $flyer->pages->first();
                    $coverUrl = $coverPage ? R2Url::asset($coverPage->image_path) : '/img/placeholder-flyer.png';
                @endphp
                <article class="group flex flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition hover:shadow-md">
                    <a href="{{ route('flyers.show', $flyer->slug) }}" class="relative aspect-[3/4] overflow-hidden bg-slate-100">
                        <img
                            src="{{ $coverUrl }}"
                            alt="{{ $flyer->title }}"
                            width="600"
                            height="800"
                            loading="lazy"
                            class="h-full w-full object-cover transition duration-300 group-hover:scale-105"
                        >
                        <span class="absolute right-2 top-2 rounded-lg bg-slate-900/80 px-2 py-1 text-[11px] font-bold text-white backdrop-blur">
                            {{ $flyer->total_pages }} صفحة
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
                            @if ($from->isFuture())
                                <span class="font-bold text-[#023b55]">يبدأ: {{ $from->format('d/m/Y') }}</span>
                                <span class="rounded bg-[#fcc023] px-2 py-0.5 text-[10px] font-bold text-slate-900">قريباً</span>
                            @elseif ($until->isPast())
                                <span class="text-slate-400">انتهى: {{ $until->format('d/m/Y') }}</span>
                                <span class="rounded bg-slate-100 px-2 py-0.5 text-[10px] font-bold text-slate-500">منتهي</span>
                            @else
                                <span class="text-slate-500">سارٍ حتى: {{ $until->format('d/m/Y') }}</span>
                                <span class="rounded bg-[#039652]/10 px-2 py-0.5 font-bold text-[#039652]">سارٍ الآن</span>
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

    <!-- جدول / بطاقات أقوى السلع المخفضة (Hot Items Spotlight) - Green sale price -->
    @if ($hotItems->isNotEmpty())
        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="mb-4 text-lg font-black text-[#023b55]">أقوى تخفيضات السلع والمنتجات اليوم</h2>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                @foreach ($hotItems as $item)
                    <div class="flex flex-col rounded-xl border border-slate-200 bg-white p-3 shadow-sm hover:border-[#039652]/20">
                        <span class="text-[11px] font-semibold text-[#023b55]">{{ $item->flyer->retailer->name }}</span>
                        <div class="mt-1 font-bold text-[#023b55] text-xs line-clamp-2">{{ $item->product_name }}</div>
                        <div class="mt-auto pt-2">
                            @if ($item->old_price)
                                <span class="text-[11px] text-slate-400 line-through">{{ number_format((float) $item->old_price, 2) }} ج.م</span>
                            @endif
                            <div class="text-sm font-black text-[#039652]">{{ number_format((float) $item->sale_price, 2) }} ج.م</div>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    @endif
</x-layouts.app>
