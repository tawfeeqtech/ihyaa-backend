<?php

/**
 * إعدادات محرك PDF (mPDF) — EPIC-05 (US-028).
 *
 * خط Amiri (عربي — من aliftype/amiri) يُسجَّل في fontdata ويُتوقع وجوده في
 * storage/app/fonts (amiri_regular.ttf + amiri_bold.ttf). dejavusans يبقى
 * احتياطياً للاتيني (en) ويدعم العربية أيضاً.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | إعدادات mPDF الأساسية
    |--------------------------------------------------------------------------
    */
    'format' => 'A4',
    'mode' => 'utf-8',
    'default_font_size' => 11,
    'margin_left' => 14,
    'margin_right' => 14,
    'margin_top' => 14,
    'margin_bottom' => 16,

    // RTL عربي سليم (US-028-S4): الضبط التلقائي للغات والخطوط + تشكيل سليم.
    'auto_script_to_lang' => true,
    'auto_lang_to_font' => true,
    'use_kwt' => true,
    'keep_table_proportions' => true,
    'shrink_tables_to_fit' => 1.4,

    // مدة كاش التقرير (ثوانٍ — US-028 §2: 3600 = ساعة واحدة).
    'cache_seconds' => 3600,

    /*
    |--------------------------------------------------------------------------
    | دليل الخطوط
    |--------------------------------------------------------------------------
    | نضيف دليل الخطوط الخاص بنا (Amiri) إلى دليل ttfonts الافتراضي لمحرك mPDF.
    */
    'font_dir' => [
        storage_path('app/fonts'),
        base_path('vendor/mpdf/mpdf/ttfonts'),
    ],

    /*
    |--------------------------------------------------------------------------
    | تسجيل الخطوط المخصصة في fontdata
    |--------------------------------------------------------------------------
    | Amiri — خط عربي بمحارف تشكيل كاملة. R = عادي، B = عريض.
    */
    'font_data' => [
        'amiri' => [
            'R' => 'amiri_regular.ttf',
            'B' => 'amiri_bold.ttf',
            'I' => 'amiri_regular.ttf',
            'BI' => 'amiri_bold.ttf',
        ],
    ],
];
