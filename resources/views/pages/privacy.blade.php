<x-layouts.app :meta-title="$metaTitle ?? 'سياسة الخصوصية'" :meta-description="$metaDescription ?? ''" og-type="website">
@push('schema')
<script type="application/ld+json">{!! json_encode(['@context'=>'https://schema.org','@type'=>'WebPage','name'=>'سياسة الخصوصية — مجلاتنا','description'=>$metaDescription ?? '','url'=>url()->current(),'isPartOf'=>['@type'=>'WebSite','name'=>config('app.name','مجلاتنا'),'url'=>url('/')]], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) !!}</script>
@endpush

<nav class="mb-4 text-xs font-semibold text-slate-500" aria-label="Breadcrumb">
    <ol class="flex items-center gap-1.5">
        <li><a href="{{ route('home') }}" class="hover:text-[#039652]">الرئيسية</a></li>
        <li>/</li>
        <li class="text-[#023b55] font-bold" aria-current="page">سياسة الخصوصية</li>
    </ol>
</nav>

<section class="mb-6 rounded-2xl border border-[#023b55]/10 bg-white p-6 sm:p-8 shadow-sm">
    <span class="inline-block rounded-full bg-[#023b55]/10 px-3 py-1 text-xs font-bold text-[#023b55]">آخر تحديث: 8 سبتمبر 2026</span>
    <h1 class="mt-3 text-2xl font-black text-[#023b55] sm:text-3xl">سياسة الخصوصية لمنصة مجلاتنا</h1>
    <p class="mt-2 text-sm text-slate-600">تحظى خصوصية زوارنا في <strong>مجلاتنا (magalatna.com)</strong> بأهمية بالغة. نوضح هنا أنواع المعلومات التي نجمعها وكيفية استخدامها وحمايتها وفق القانون المصري 151 لسنة 2020.</p>
</section>

<div class="mx-auto max-w-4xl rounded-2xl border border-slate-200 bg-white p-6 sm:p-8 shadow-sm">
<article class="prose prose-slate max-w-none prose-headings:text-[#023b55] prose-headings:font-black prose-a:text-[#039652] prose-strong:text-[#023b55]">
    <h2>1. ملفات السجلات (Log Files)</h2>
    <p>
        مثل معظم مواقع الإنترنت، يستخدم موقع مجلاتنا ملفات السجلات لتحليل الاتجاهات وإدارة الموقع. تتضمن المعلومات التي يتم جمعها: عناوين بروتوكول الإنترنت (IP Addresses)، نوع المتصفح، مزود خدمة الإنترنت (ISP)، طوابع التاريخ/الوقت، والصفحات التي تمت زيارتها. هذه المعلومات لا ترتبط بأي بيانات تحدد هويتك الشخصية وتُستخدم فقط لتحسين سرعة السيرفر والأداء العام.
    </p>

    <h2>2. ملفات تعريف الارتباط (Cookies) وإشارات الويب</h2>
    <p>
        نستخدم ملفات تعريف الارتباط لتخزين تفضيلات الزوار (مثل الدولة المفضلة، أو إعدادات عارض الصور) لتخصيص تجربة التصفح وجعلها أكثر سرعة وسلاسة.
    </p>

    <h2>3. شركاء الإعلانات وملف تعريف الارتباط Google DART</h2>
    <p>
        تعد شركة <strong>Google</strong> أحد البائعين التابعين لأطراف ثالثة على موقعنا:
    </p>
    <ul>
        <li>تستخدم Google ملفات تعريف الارتباط، وتحديداً ملف تعريف الارتباط <strong>DART</strong>، لعرض الإعلانات لزوار موقعنا استناداً إلى زياراتهم لموقع magalatna.com وغيره من المواقع على شبكة الإنترنت.</li>
        <li>يجوز للمستخدمين إلغاء الاشتراك في استخدام ملف تعريف الارتباط DART وزيارة سياسة خصوصية شبكة إعلانات Google والمحتوى عبر الرابط التالي:
        <a href="https://policies.google.com/technologies/ads" target="_blank" rel="noopener nofollow">https://policies.google.com/technologies/ads</a>.</li>
    </ul>

    <h2>4. إعلانات الأطراف الثالثة وشبكات التتبع</h2>
    <p>
        قد تستخدم شبكات الإعلانات التابعة لأطراف ثالثة (مثل Google AdSense) تقنيات مثل ملفات تعريف الارتباط أو جافاسكربت في إعلاناتها وروابطها التي تظهر على موقعنا. تتلقى هذه الخوادم عنوان IP الخاص بك تلقائياً عند حدوث ذلك لقياس فاعلية حملاتهم الإعلانية أو لتخصيص المحتوى الإعلاني. لا تملك منصة مجلاتنا أي وصول أو تحكم في ملفات تعريف الارتباط التي يستخدمها هؤلاء المعلنون.
    </p>

    <h2>5. خصوصية الأطفال</h2>
    <p>
        نحن نعتبر حماية الأطفال أثناء استخدام الإنترنت أمراً بالغ الأهمية. موقع مجلاتنا لا يقوم بجمع أي معلومات تعريفية شخصية عن قصد من الأطفال دون سن 13 عاماً. إذا كان لديك ما يدعو للاعتقاد بأن طفلك قدم مثل هذه المعلومات على موقعنا، يرجى الاتصال بنا فوراً لحذفها من سجلاتنا.
    </p>

    <h2>6. حقوق المستخدم</h2>
    <p>
        يحق لك في أي وقت تعطيل ملفات تعريف الارتباط من خلال خيارات المتصفح الخاص بك، كما يحق لك مراسلتنا لطلب الاستفسار عن أي بيانات تقنية مخزنة أو طلب حذفها.
    </p>

    <h2>7. الموافقة</h2>
    <p>
        باستخدامك لموقعنا، فإنك تقر وتوافق على سياسة الخصوصية الخاصة بنا وشروط العمل بها.
    </p>
</article>
</div>
</x-layouts.app>
