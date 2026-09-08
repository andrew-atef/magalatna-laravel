<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\View\View;

final class PageController extends Controller
{
    public function about(): View
    {
        return view('pages.about', [
            'metaTitle' => 'من نحن',
            'metaDescription' => 'تعرف على منصة مجلاتنا — منصة رقمية مصرية مستقلة متخصصة في رصد وأرشفة مجلات عروض وتخفيضات كبرى سلاسل التجزئة في مصر.',
        ]);
    }

    public function privacy(): View
    {
        return view('pages.privacy', [
            'metaTitle' => 'سياسة الخصوصية',
            'metaDescription' => 'سياسة الخصوصية لمنصة مجلاتنا — كيف نجمع ونحمي بيانات زوار magalatna.com وفق القانون المصري 151 لسنة 2020 وسياسات Google AdSense.',
        ]);
    }

    public function terms(): View
    {
        return view('pages.terms', [
            'metaTitle' => 'شروط وأحكام الاستخدام',
            'metaDescription' => 'شروط وأحكام استخدام منصة مجلاتنا — حقوق الملكية، صحة الأسعار، الاستخدام المقبول والقانون الحاكم في جمهورية مصر العربية.',
        ]);
    }

    public function contact(): View
    {
        return view('pages.contact', [
            'metaTitle' => 'اتصل بنا',
            'metaDescription' => 'اتصل بإدارة مجلاتنا — contact@magalatna.com للاستفسارات و legal@magalatna.com للشؤون القانونية، نرد خلال 24-48 ساعة عمل.',
        ]);
    }
}
