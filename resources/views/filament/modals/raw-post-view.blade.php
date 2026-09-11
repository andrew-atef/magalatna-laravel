<div class="space-y-6" dir="rtl">
    <div class="rounded-xl border border-slate-200 bg-white p-4">
        <h3 class="text-sm font-black text-[#023b55] mb-2">نص المنشور الكامل</h3>
        <div class="whitespace-pre-wrap text-sm leading-relaxed text-slate-700 bg-slate-50 rounded-lg p-3 border border-slate-100">{{ $record->post_text ?: '— لا يوجد نص —' }}</div>
        <div class="mt-3 grid grid-cols-2 gap-2 text-xs text-slate-500">
            <div><span class="font-bold">المتجر:</span> {{ $record->retailer->name ?? '—' }}</div>
            <div><span class="font-bold">Facebook Post ID:</span> {{ $record->facebook_post_id }}</div>
            <div><span class="font-bold">الحالة:</span> <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-bold
                @if($record->status === 'pending') bg-amber-100 text-amber-800
                @elseif($record->status === 'accepted') bg-emerald-100 text-emerald-800
                @elseif($record->status === 'rejected') bg-red-100 text-red-800
                @else bg-blue-100 text-blue-800 @endif
            ">{{ $record->status }}</span></div>
            <div><span class="font-bold">تاريخ النشر:</span> {{ $record->published_at?->format('Y-m-d H:i') ?? '—' }}</div>
            @if($record->flyer)
                <div class="col-span-2"><span class="font-bold">المجلة المرتبطة:</span> <a href="{{ route('flyers.show', $record->flyer->slug) }}" target="_blank" class="text-[#039652] hover:underline">{{ $record->flyer->title }}</a></div>
            @endif
        </div>
    </div>

    <div class="rounded-xl border border-slate-200 bg-white p-4">
        <h3 class="text-sm font-black text-[#023b55] mb-3">الصور المرفقة ({{ is_array($record->image_urls) ? count($record->image_urls) : 0 }})</h3>
        @if(is_array($record->image_urls) && count($record->image_urls) > 0)
            <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                @foreach($record->image_urls as $url)
                    <a href="{{ $url }}" target="_blank" class="group block overflow-hidden rounded-xl border border-slate-200 hover:border-[#039652]/50 transition">
                        <img src="{{ $url }}" alt="صورة {{ $loop->iteration }}" class="h-48 w-full object-cover group-hover:scale-[1.02] transition" loading="lazy" />
                        <div class="p-2 text-[11px] text-slate-500 truncate">{{ $url }}</div>
                    </a>
                @endforeach
            </div>
        @else
            <p class="text-sm text-slate-500">لا توجد صور.</p>
        @endif
    </div>

    <div class="rounded-xl border border-slate-200 bg-white p-4">
        <h3 class="text-sm font-black text-[#023b55] mb-2">استجابة الذكاء الاصطناعي (AI Classification)</h3>
        @if($record->ai_classification)
            <pre class="whitespace-pre-wrap break-words text-xs leading-relaxed text-slate-700 bg-slate-950 text-slate-100 rounded-lg p-3 overflow-x-auto" dir="ltr">{{ json_encode($record->ai_classification, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
            @if($record->rejection_reason)
                <div class="mt-3 rounded-lg bg-red-50 border border-red-200 p-3">
                    <div class="text-xs font-black text-red-800">سبب الرفض:</div>
                    <div class="text-sm text-red-700 mt-1">{{ $record->rejection_reason }}</div>
                </div>
            @endif
        @else
            <p class="text-sm text-slate-500">لم يتم تصنيف المنشور بعد (قيد الانتظار).</p>
        @endif
    </div>
</div>
