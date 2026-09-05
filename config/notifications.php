<?php

/*
|--------------------------------------------------------------------------
| كتالوج الإشعارات — EPIC-09 (T006 · T018)
|--------------------------------------------------------------------------
| المصدر الوحيد للحقيقة لنوع كل إشعار ولأولويته:
|   is_critical = true  → يُبث فورياً عبر Reverb (interest_new, evaluation_completed)
|   is_critical = false → يُخزَّن في DB ويُجلب عند إعادة التحميل (US-049)
|
| القيد ضد تضخم الاتصال (US-048): أي نوع خارج `critical_types` لا يُبث أبداً
| حتى لو مرَّر المتصل is_critical=true — الحماية صارمة داخل NotificationService.
*/

return [

    'types' => [
        'interest_new' => [
            'label' => 'interest_new',
            'is_critical' => true,
        ],
        'evaluation_completed' => [
            'label' => 'evaluation_completed',
            'is_critical' => true,
        ],
        'interest_accepted' => [
            'label' => 'interest_accepted',
            'is_critical' => false,
        ],
        'interest_rejected' => [
            'label' => 'interest_rejected',
            'is_critical' => false,
        ],
        'interest_cancelled' => [
            'label' => 'interest_cancelled',
            'is_critical' => false,
        ],
        'analysis_completed' => [
            'label' => 'analysis_completed',
            'is_critical' => false,
        ],
        'pdf_generation_failed' => [
            'label' => 'pdf_generation_failed',
            'is_critical' => false,
        ],
    ],

    /*
    | قائمة الأنواع الحرجية — تُستخدم للحارس الصارم في NotificationService
    | (لا بث لغير الحرجة — SC-002 · US-048).
    */
    'critical_types' => ['interest_new', 'evaluation_completed'],

];
