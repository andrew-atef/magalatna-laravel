<x-layouts.app :meta-title="$metaTitle ?? 'اتصل بنا'" :meta-description="$metaDescription ?? ''" og-type="website">
@push('schema')
<script type="application/ld+json">{!! json_encode(['@context'=>'https://schema.org','@type'=>'ContactPage','name'=>'اتصل بنا — مجلاتنا','description'=>$metaDescription ?? '','url'=>url()->current(),'isPartOf'=>['@type'=>'WebSite','name'=>config('app.name','مجلاتنا'),'url'=>url('/')]], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) !!}</script>
@endpush

<nav class="mb-4 text-xs font-semibold text-slate-500" aria-label="Breadcrumb">
    <ol class="flex items-center gap-1.5">
        <li><a href="{{ route('home') }}" class="hover:text-[#039652]">الرئيسية</a></li>
        <li>/</li>
        <li class="text-[#023b55] font-bold" aria-current="page">اتصل بنا</li>
    </ol>
</nav>

<section class="mb-6 rounded-2xl border border-[#023b55]/10 bg-white p-6 sm:p-8 shadow-sm">
    <h1 class="text-2xl font-black text-[#023b55] sm:text-3xl">اتصل بإدارة مجلاتنا</h1>
    <p class="mt-2 max-w-3xl text-sm leading-relaxed text-slate-600">يسعدنا دائماً التواصل معكم واستقبال آرائكم ومقترحاتكم للمساعدة في تطوير المنصة وخدمة المستهلكين في مصر.</p>
</section>

<div class="mx-auto max-w-4xl rounded-2xl border border-slate-200 bg-white p-6 sm:p-8 shadow-sm">
<article class="prose prose-slate max-w-none prose-headings:text-[#023b55] prose-headings:font-black prose-a:text-[#039652] prose-strong:text-[#023b55]">
    <div class="my-6 not-prose">
        <div class="bg-slate-50 p-6 rounded-2xl border border-slate-200 text-center sm:text-right">
            <div class="mx-auto sm:mx-0 w-10 h-10 bg-[#023b55] text-white rounded-xl flex items-center justify-center font-bold text-lg mb-4">✉️</div>
            <h3 class="text-base font-black text-[#023b55] mb-1 not-prose">البريد الإلكتروني للتواصل</h3>
            <p class="text-xs text-slate-500 mb-3">للاستفسارات والمقترحات:</p>
            <a href="mailto:contact@magalatna.com" class="text-base font-bold text-[#039652] hover:underline">contact@magalatna.com</a>
        </div>
    </div>
</article>
</div>
</x-layouts.app>
