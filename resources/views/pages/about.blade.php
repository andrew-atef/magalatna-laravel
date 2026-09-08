<x-layouts.app :meta-title="$metaTitle ?? 'من نحن'" :meta-description="$metaDescription ?? ''" og-type="website">
@push('schema')
<script type="application/ld+json">{!! json_encode(['@context'=>'https://schema.org','@type'=>'AboutPage','name'=>'من نحن — مجلاتنا','description'=>$metaDescription ?? '','url'=>url()->current(),'isPartOf'=>['@type'=>'WebSite','name'=>config('app.name','مجلاتنا'),'url'=>url('/')],'breadcrumb'=>['@type'=>'BreadcrumbList','itemListElement'=>[['@type'=>'ListItem','position'=>1,'name'=>'الرئيسية','item'=>url('/')],['@type'=>'ListItem','position'=>2,'name'=>'من نحن','item'=>url()->current()]]]], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) !!}</script>
@endpush

<nav class="mb-4 text-xs font-semibold text-slate-500" aria-label="Breadcrumb">
    <ol class="flex items-center gap-1.5">
        <li><a href="{{ route('home') }}" class="hover:text-[#039652]">الرئيسية</a></li>
        <li>/</li>
        <li class="text-[#023b55] font-bold" aria-current="page">من نحن</li>
    </ol>
</nav>

<section class="mb-6 rounded-2xl border border-[#023b55]/10 bg-white p-6 sm:p-8 shadow-sm">
    <span class="inline-block rounded-full bg-[#023b55]/10 px-3 py-1 text-xs font-bold text-[#023b55]">Magalatna.com — منصة مستقلة</span>
    <h1 class="mt-3 text-2xl font-black text-[#023b55] sm:text-3xl">من نحن — منصة مجلاتنا (Magalatna.com)</h1>
    <p class="mt-3 max-w-3xl text-sm leading-relaxed text-slate-600">
        <strong>مجلاتنا (Magalatna.com)</strong> هي منصة رقمية مصرية مستقلة متخصصة في رصد، وأرشفة، وتفريغ بيانات مجلات العروض وتخفيضات الأسعار الصادرة عن كبرى سلاسل التجزئة والأسواق التجارية في جمهورية مصر العربية.
    </p>
</section>

<div class="mx-auto max-w-4xl rounded-2xl border border-slate-200 bg-white p-6 sm:p-8 shadow-sm">
<article class="prose prose-slate max-w-none prose-headings:text-[#023b55] prose-headings:font-black prose-a:text-[#039652] prose-strong:text-[#023b55]">
    <h2>رؤيتنا ورسالتنا</h2>
    <p>
        في ظل تسارع وتيرة الأسواق وتقلبات الأسعار اليومية، تأسست منصة <strong>مجلاتنا</strong> بهدف واحد واضح: <strong>تمكين المستهلك ورب الأسرة المصرية من اتخاذ قرارات شرائية ذكية وموفرة لميزانية البيت</strong>. نحن نؤمن بأن الوصول إلى المعلومة السعرية الدقيقة يجب أن يكون سهلاً، سريعاً، ومجانياً للجميع.
    </p>

    <h2>ماذا نقدم لزوارنا؟</h2>
    <ul>
        <li><strong>التغطية اللحظية الشاملة:</strong> متابعة وتوثيق مجلات كبرى السلاسل (مثل: كازيون، كارفور، بيم، هايبر وان، فتح الله، أسواق المرشدي، ورنين) فور صدورها رسمياً.</li>
        <li><strong>تفريغ البيانات الرقمي (Data Structuring):</strong> لا نكتفي بنشر الصور فقط؛ بل نقوم باستخراج أسعار السلع، ومقدار التوفير بالجنيه، ونسب الخصم الحقيقية داخل جداول تفاعلية سهلة الفرز والبحث.</li>
        <li><strong>أرشفة الأسعار وتاريخ التخفيضات:</strong> توثيق موعد بداية ونهاية كل مجلة لمنع تضليل المستهلك بالعروض المنتهية، ومساعدته على مقارنة تطور أسعار السلع عبر الزمن.</li>
        <li><strong>تجربة تصفح فائقة السرعة:</strong> تقديم عارض مجلات مصور خفيف الوزن بتقنيات حديثة متوافقة بالكامل مع كافة الهواتف المحمولة وبأقل استهلاك لباقة الإنترنت.</li>
    </ul>

    <h2>معايير الدقة والحيادية</h2>
    <p>
        تلتزم منصة <strong>مجلاتنا</strong> بأعلى درجات الحياد والنزاهة؛ نحن لا نتبع أي سلسلة تجارية ولا نروج لمتجر على حساب آخر. كافة البيانات المعروضة في جداولنا يتم تدقيقها ومطابقتها حرفياً مع ما هو معلن في المطبوعات والمجلات الرسمية للشركات الناشرة.
    </p>

    <h2>إخلاء مسؤولية الملكية الفكرية</h2>
    <p class="bg-slate-50 p-4 rounded-xl border border-slate-200 text-xs text-slate-600">
        منصة <strong>مجلاتنا</strong> هي منصة إخبارية وخدمية مستقلة. جميع الأسماء التجارية، الشعارات، الصور، والمجلات الإعلانية المنشورة على هذا الموقع هي ملكية حصرية لأصحابها وناشريها الرسميين وتخضع لحقوق الطبع والنشر الخاصة بتلك الشركات. يتم إدراجها على موقعنا لأغراض التوثيق الإخباري وتسهيل وصول المستهلك للمعلومة السعرية العامة بموجب مبادئ الاستخدام العادل (Fair Use).
    </p>
</article>
</div>
</x-layouts.app>
