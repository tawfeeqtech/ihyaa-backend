<?php

namespace App\Services\AI;

use Illuminate\Support\Str;

/**
 * تعقيم المدخلات قبل بناء البرومبت — الدستور V (حماية من حقن البرومبت).
 *
 * تقطيع الطول + إزالة المحارف الضابطة + تجريد محاولات الهروب (``` و {{ و <script>
 * وتعليمات "تجاهل ما سبق") من نص المشروع قبل إرفاقه بالبرومبت، ثم توجيه صريح
 * للنموذج "استخدم فقط البيانات المرفقة". النصوص/القوالب فقط (الدستور VI).
 */
class PromptSanitizer
{
    /** إزالة محارف التحكم وعلامات الخطر من نص حر */
    public function text(?string $value, int $max = 500): string
    {
        $value = (string) $value;

        // محارف التحكم (ASCII 0-31 + DEL) قد تحمل تعليمات مضمّنة
        $value = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value) ?? $value;

        // تجريد أدوات التنسيق/الترميز التي قد تُستخدم لخداع النموذج
        $value = str_ireplace(
            ['```', '{{', '}}', '<script', '</script', 'ignore previous', 'تجاهل التعليمات', 'System:'],
            '',
            $value,
        );

        return Str::limit(trim($value), $max, '…');
    }

    /** تعقيم قائمة نصوص مع حد أقصى لعدد العناصر */
    public function list(array $values, int $maxItems = 20, int $maxLen = 200): array
    {
        return array_map(
            fn ($v) => $this->text(is_string($v) ? $v : (string) json_encode($v, JSON_UNESCAPED_UNICODE), $maxLen),
            array_slice($values, 0, $maxItems),
        );
    }
}
