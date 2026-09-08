@php
    use App\Support\R2Url;
    $siteName = config('app.name', 'عروض نت');
    $searchQuery = $searchQuery ?? request('q', '');
    $selectedRetailerSlug = request('retailer');
    $r2DiskUrl = R2Url::base();
    $robotsMeta = request()->filled('q') || request()->has('page') ? 'noindex, follow' : 'index, follow, max-image-size:large, max-snippet:-1, max-video-preview:-1';
@endphp

<x-layouts.app
    :meta-title="$searchQuery ? 'نتائج البحث عن ' . $searchQuery : 'أحدث عروض ومجلات السوبرماركت في مصر اليوم'"
    :meta-description="'تصفح أحدث عروض وتخفيضات سلاسل السوبرماركت في مصر (كازيون، كارفور، بيم، فتح الله). أسعار السلع والجبن والزيوت محدثة ومفهرسة يومياً.'"
    :robots="$robotsMeta"
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

    <!-- Active Search Notification Bar -->
    @if (request()->filled('q'))
        <div class="mb-6 flex flex-col gap-2 rounded-xl border border-[#039652]/20 bg-[#039652]/10 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-xs font-bold text-[#023b55] sm:text-sm">
                نتائج البحث عن: <span class="font-black text-[#039652]">"{{ $searchQuery }}"</span>
                — تم العثور على <span class="font-black">{{ $matchingItems?->total() ?? 0 }}</span> منتج و <span class="font-black">{{ $flyers->total() }}</span> مجلة.
            </p>
            <a href="{{ route('home') }}" class="inline-flex shrink-0 items-center gap-1 rounded-full bg-[#023b55] px-3 py-1 text-xs font-black text-white hover:bg-[#039652] transition">
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
                    <a href="{{ route('flyers.show', $item->flyer->slug) }}#item-{{ $item->id }}" class="group flex flex-col rounded-xl border border-slate-200 bg-white p-3 shadow-sm transition hover:border-[#039652] hover:shadow-md active:border-[#039652] active:shadow-md" aria-label="{{ $item->product_name }} - {{ $item->flyer->retailer->name }}">
                        <span class="flex items-center justify-between gap-1.5">
                            <a href="{{ route('retailers.show', $item->flyer->retailer->slug) }}" onclick="event.stopPropagation()" class="inline-flex items-center gap-1 rounded-full bg-[#023b55]/10 px-2 py-0.5 text-[10px] font-bold text-[#023b55] hover:bg-[#023b55] hover:text-white transition">
                                {{ $item->flyer->retailer->name }}
                            </a>
                            @if ($item->discount_percent)
                                <span class="rounded-full bg-[#039652]/10 px-1.5 py-0.5 text-[10px] font-black text-[#039652]">-{{ round((float) $item->discount_percent) }}%</span>
                            @endif
                        </span>
                        <div class="mt-2 line-clamp-2 text-xs font-bold text-[#023b55] group-hover:text-[#039652] transition-colors">{{ $item->product_name }}</div>
                        @if ($item->unit)
                            <span class="mt-1 text-[10px] text-slate-500">{{ $item->unit }}</span>
                        @endif
                        <div class="mt-auto pt-2">
                            @if ($item->old_price)
                                <span class="text-[11px] text-slate-400 line-through">{{ number_format((float) $item->old_price, 2) }} ج.م</span>
                            @endif
                            <div class="text-sm font-black text-[#039652]">{{ number_format((float) $item->sale_price, 2) }} ج.م</div>
                        </div>
                    </a>
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
                <a href="{{ route('home') }}" class="mt-6 inline-flex rounded-xl bg-[#039652] px-4 py-2 text-xs font-black text-white hover:bg-[#027a42] transition">العودة لجميع العروض</a>
            </div>
        </section>
    @endif

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

    <!-- جدول / بطاقات أقوى السلع المخفضة (Hot Items Spotlight) - Deep-linked clickable cards -->
    @if ($hotItems->isNotEmpty())
        <section id="hot-deals" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm scroll-mt-20">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-lg font-black text-[#023b55]">أقوى تخفيضات السلع والمنتجات اليوم</h2>
                <a href="{{ route('home', ['type' => 'magazines']) }}" class="text-xs font-bold text-[#039652] hover:underline">عرض الكل ←</a>
            </div>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                @foreach ($hotItems as $item)
                    <a href="{{ route('flyers.show', $item->flyer->slug) }}#item-{{ $item->id }}" class="group flex flex-col rounded-xl border border-slate-200 bg-white p-3 shadow-sm transition hover:border-[#039652] hover:shadow-md active:border-[#039652] active:shadow-md" aria-label="{{ $item->product_name }} - {{ $item->flyer->retailer->name }} بـ {{ number_format((float) $item->sale_price, 2) }} ج.م">
                        <span class="flex items-center justify-between gap-1.5">
                            <a href="{{ route('retailers.show', $item->flyer->retailer->slug) }}" onclick="event.stopPropagation()" class="inline-flex items-center gap-1 rounded-full bg-[#023b55]/10 px-2 py-0.5 text-[10px] font-bold text-[#023b55] hover:bg-[#023b55] hover:text-white transition">
                                {{ $item->flyer->retailer->name }}
                            </a>
                            @if ($item->discount_percent)
                                <span class="rounded-full bg-[#039652]/10 px-1.5 py-0.5 text-[10px] font-black text-[#039652]">-{{ round((float) $item->discount_percent) }}%</span>
                            @endif
                        </span>
                        <div class="mt-2 font-bold text-[#023b55] text-xs line-clamp-2 group-hover:text-[#039652] transition-colors">{{ $item->product_name }}</div>
                        <div class="mt-auto pt-2">
                            @if ($item->old_price)
                                <span class="text-[11px] text-slate-400 line-through">{{ number_format((float) $item->old_price, 2) }} ج.م</span>
                            @endif
                            <div class="text-sm font-black text-[#039652]">{{ number_format((float) $item->sale_price, 2) }} ج.م</div>
                            @if ($item->unit)
                                <span class="text-[10px] text-slate-500">{{ $item->unit }}</span>
                            @endif
                        </div>
                    </a>
                @endforeach
            </div>
        </section>
    @endif
</x-layouts.app>
