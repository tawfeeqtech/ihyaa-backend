<?php

/*
|--------------------------------------------------------------------------
| Upload Limits — T135 · SRS-F02-02 · SRS-API-18
|--------------------------------------------------------------------------
| حدود مركزية واحدة لكل عمليات الرفع في المنصة (صور المشروع، ملفات PDF،
| الصورة الشخصية). تُقرأ من هنا في قواعد Laravel وخدمة FileValidationService.
|
|  - images : 5 ملفات × 5120KB (jpeg/png/webp)
|  - pdfs   : 3 ملفات × 10240KB (pdf)
|  - avatar : صورة واحدة × 2048KB (jpeg/png/webp)
*/

return [

    'images' => [
        'count' => 5,
        'max_kb' => 5120,          // 5MB بالكيلوبايت
        'mimes' => ['jpeg', 'png', 'webp'],
    ],

    'pdfs' => [
        'count' => 3,
        'max_kb' => 10240,         // 10MB
        'mimes' => ['pdf'],
    ],

    'avatar' => [
        'count' => 1,
        'max_kb' => 2048,          // 2MB
        'mimes' => ['jpeg', 'png', 'webp'],
    ],

];
