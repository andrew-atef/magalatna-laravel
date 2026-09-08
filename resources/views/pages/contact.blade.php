<x-layouts.app :meta-title="$metaTitle ?? 'اتصل بنا'" :meta-description="$metaDescription ?? ''" og-type="website">
@push('schema')
<script type="application/ld+json">{!! json_encode(['@context'=>'https://schema.org','@type'=>'ContactPage','name'=>'اتصل بنا — مجلاتنا','url'=>url()->current(),'isPartOf'=>['@type'=>'WebSite','name'=>config('app.name','مجلاتنا'),'url'=>url('/')]], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) !!}</script>
@endpush
<div class="mx-auto max-w-4xl rounded-2xl border border-slate-200 bg-white p-6 sm:p-8 shadow-sm">
<article class="prose prose-slate max-w-none">
    <h1>اتصل بإدارة مجلاتنا</h1>
    <p class="lead text-slate-700">
        يسعدنا دائماً التواصل معكم واستقبال آرائكم ومقترحاتكم للمساعدة في تطوير المنصة وخدمة المستهلكين في مصر.
    </p>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 my-8 not-prose">
        <!-- كارت البريد الإلكتروني -->
        <div class="bg-slate-50 p-6 rounded-2xl border border-slate-200">
            <div class="w-10 h-10 bg-[#023b55] text-white rounded-xl flex items-center justify-center font-bold text-lg mb-4">✉️</div>
            <h3 class="text-base font-black text-[#023b55] mb-1">البريد الإلكتروني العام</h3>
            <p class="text-xs text-slate-500 mb-3">للاستفسارات العامة والمقترحات والشكاوى:</p>
            <a href="mailto:contact@magalatna.com" class="text-sm font-bold text-[#039652] hover:underline">contact@magalatna.com</a>
        </div>

        <!-- كارت الشؤون القانونية وحقوق النشر -->
        <div class="bg-slate-50 p-6 rounded-2xl border border-slate-200">
            <div class="w-10 h-10 bg-[#023b55] text-white rounded-xl flex items-center justify-center font-bold text-lg mb-4">⚖️</div>
            <h3 class="text-base font-black text-[#023b55] mb-1">الشؤون القانونية وحقوق الملكية</h3>
            <p class="text-xs text-slate-500 mb-3">لطلبات التدقيق أو إزالة المحتوى (DMCA):</p>
            <a href="mailto:legal@magalatna.com" class="text-sm font-bold text-[#039652] hover:underline">legal@magalatna.com</a>
        </div>
    </div>

    <h2>قنوات التواصل المباشرة</h2>
    <ul>
        <li><strong>المقر الإداري:</strong> القاهرة، جمهورية مصر العربية.</li>
        <li><strong>أوقات الرد:</strong> يقوم فريق العمل بالرد على كافة الرسائل والطلبات في غضون <strong>24 إلى 48 ساعة عمل</strong>.</li>
    </ul>

    <h2>الإبلاغ عن خطأ في الأسعار</h2>
    <p>
        إذا لاحظت وجود أي خطأ في تفريغ سعر سلعة معينة أو تاريخ سريان مجلة، يُرجى تزويدنا برابط الصفحة واسم السلعة وسيقوم الفريق التقني بتدقيقها وتعديلها فوراً لضمان مصداقية البيانات.
    </p>
</article>
</div>
</x-layouts.app>
