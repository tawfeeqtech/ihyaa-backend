<?php

namespace App\Services\Reports;

/**
 * راسم الرادار البنتاغوني كـ SVG قابل للتضمين — US-025-S2 (T104).
 *
 * خمسة محاور بزاوية 72° تبدأ من الأعلى (-90°) — مطابق RadarChart.jsx.
 * القيم من 0..100 تُسقط على نصف قطر ثابت. يُستخدم في PDF (mPDF يدعم SVG)
 * ويُبنى من `radar_chart.axes` (مصدر واحد — نفس القيم المخزَّنة).
 */
class RadarSvgRenderer
{
    private const WIDTH = 420;

    private const HEIGHT = 380;

    private const CX = 210;

    private const CY = 185;

    private const RADIUS = 140;

    private const RINGS = [25, 50, 75, 100]; // خطوط الشبكة عند 25/50/75/100%

    /**
     * بناء SVG الرادار من محاور.
     *
     * @param  list<array{dimension: string, label_ar: string, value: float}>  $axes
     * @return string سلسلة `<svg>...</svg>` كاملة
     */
    public function render(array $axes): string
    {
        $count = count($axes);

        if ($count < 3) {
            return $this->emptySvg('بيانات الأبعاد غير كافية لرسم الرادار');
        }

        $step = 360 / $count;
        $points = [];

        for ($i = 0; $i < $count; $i++) {
            $angle = deg2rad(-90 + $i * $step);
            $value = max(0, min(100, (float) $axes[$i]['value']));
            $radius = self::RADIUS * $value / 100;

            $points[] = [
                'x' => self::CX + $radius * cos($angle),
                'y' => self::CY + $radius * sin($angle),
                // نقطة الرأس الكاملة للمحور (للخطوط والشبكة)
                'vx' => self::CX + self::RADIUS * cos($angle),
                'vy' => self::CY + self::RADIUS * sin($angle),
            ];
        }

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="'.self::WIDTH.'" height="'.self::HEIGHT.'" viewBox="0 0 '.self::WIDTH.' '.self::HEIGHT.'">';

        // ——— الشبكة: مضلعات الحلقات ———
        foreach (self::RINGS as $ring) {
            $svg .= '<polygon points="'.$this->ringPoints($count, $ring).'" '
                .'fill="none" stroke="#d1d5db" stroke-width="1"/>';
        }

        // ——— المحاور ———
        foreach ($points as $p) {
            $svg .= '<line x1="'.self::CX.'" y1="'.self::CY.'" x2="'.round($p['vx'], 2).'" y2="'.round($p['vy'], 2).'" '
                .'stroke="#d1d5db" stroke-width="1"/>';
        }

        // ——— مضلع البيانات ———
        $dataPoints = array_map(
            static fn (array $p): string => round($p['x'], 2).','.round($p['y'], 2),
            $points,
        );

        $svg .= '<polygon points="'.implode(' ', $dataPoints).'" fill="rgba(37,99,235,0.20)" '
            .'stroke="#2563eb" stroke-width="2" stroke-linejoin="round"/>';

        // ——— نقاط القيم ———
        foreach ($points as $p) {
            $svg .= '<circle cx="'.round($p['x'], 2).'" cy="'.round($p['y'], 2).'" r="4" fill="#2563eb"/>';
        }

        $svg .= '</svg>';

        return $svg;
    }

    /**
     * ربط نقاط حلقة (نسبة مئوية) — لإغلاق المضلع نعيد أول نقطة في النهاية.
     */
    private function ringPoints(int $count, float $percent): string
    {
        $step = 360 / $count;
        $pts = [];

        for ($i = 0; $i < $count; $i++) {
            $angle = deg2rad(-90 + $i * $step);
            $r = self::RADIUS * $percent / 100;
            $pts[] = round(self::CX + $r * cos($angle), 2).','.round(self::CY + $r * sin($angle), 2);
        }

        // إغلاق المضلع
        $pts[] = $pts[0] ?? '';

        return implode(' ', $pts);
    }

    /** SVG بديل عند نقص البيانات — رسالة بدل لوحة فاسدة (Edge Case التقرير الجزئي). */
    private function emptySvg(string $message): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="'.self::WIDTH.'" height="'.self::HEIGHT.'" viewBox="0 0 '.self::WIDTH.' '.self::HEIGHT.'">'
            .'<rect x="10" y="10" width="'.(self::WIDTH - 20).'" height="'.(self::HEIGHT - 20).'" rx="8" fill="#f9fafb" stroke="#e5e7eb"/>'
            .'<text x="'.(self::WIDTH / 2).'" y="'.(self::HEIGHT / 2).'" text-anchor="middle" font-size="14" fill="#6b7280" '
            .'font-family="DejaVu Sans, sans-serif">'.$this->escape($message).'</text>'
            .'</svg>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
