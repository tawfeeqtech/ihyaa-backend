@php
    /** قالب تقرير وكيل AI PDF — EPIC-15 (T118). RTL/LTR حسب اللغة. */
    $dir = $language === 'ar' ? 'rtl' : 'ltr';

    $t = $language === 'ar' ? [
        'title' => 'تقرير التحليل الذكي',
        'project' => 'المشروع',
        'type' => 'نوع التحليل',
        'version' => 'الإصدار',
        'date' => 'التاريخ',
        'model' => 'النموذج',
        'summary' => 'الملخص التنفيذي',
        'strengths' => 'نقاط القوة',
        'weaknesses' => 'نقاط الضعف',
        'opportunities' => 'الفرص',
        'threats' => 'التهديدات',
        'recommendations' => 'التوصيات',
        'competitive_advantage' => 'الميزة التنافسية',
        'differentiators' => 'عوامل التمييز',
        'gaps_to_address' => 'فجوات يجب معالجتها',
        'competitors' => 'المنافسون',
        'insufficient' => 'عدد المنافسين غير كافٍ (< 3) — البيانات استرشادية',
        'market_share' => 'تقدير الحصة السوقية',
        'range' => 'النطاق المتوقع',
        'share_percent' => 'الحصة التقريبية',
        'assumptions' => 'الافتراضات',
        'limitations' => 'القيود',
        'score' => 'الدرجة',
        'overlap' => 'تقاطع الوسوم',
        'no_items' => 'لا توجد عناصر',
        'footer' => 'تقرير وكيل AI — منصة إحياء',
    ] : [
        'title' => 'AI Agent Analysis Report',
        'project' => 'Project',
        'type' => 'Analysis type',
        'version' => 'Version',
        'date' => 'Date',
        'model' => 'Model',
        'summary' => 'Executive Summary',
        'strengths' => 'Strengths',
        'weaknesses' => 'Weaknesses',
        'opportunities' => 'Opportunities',
        'threats' => 'Threats',
        'recommendations' => 'Recommendations',
        'competitive_advantage' => 'Competitive Advantage',
        'differentiators' => 'Differentiators',
        'gaps_to_address' => 'Gaps to Address',
        'competitors' => 'Competitors',
        'insufficient' => 'Not enough competitors (< 3) — indicative data',
        'market_share' => 'Market Share Estimate',
        'range' => 'Expected range',
        'share_percent' => 'Approximate share',
        'assumptions' => 'Assumptions',
        'limitations' => 'Limitations',
        'score' => 'Score',
        'overlap' => 'Tag overlap',
        'no_items' => 'No items',
        'footer' => 'AI Agent Report — Ihyaa Platform',
    ];

    $type = $artifact->analysis_type?->value ?? 'comparison';
    $items = is_array($data) ? $data : [];
@endphp
<!DOCTYPE html>
<html lang="{{ $language }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <title>{{ $t['title'] }} — {{ $project->title }}</title>
    <style>
        body { font-family: inherit; color: #1f2937; font-size: 11pt; line-height: 1.6; }
        .cover { text-align: center; padding-top: 80px; }
        .logo { font-size: 26pt; font-weight: bold; color: #2563eb; }
        .tagline { color: #6b7280; font-size: 12pt; margin-top: 4px; }
        .project-title { font-size: 16pt; font-weight: bold; margin-top: 30px; }
        .meta { color: #6b7280; font-size: 10pt; margin-top: 6px; }
        h2 { color: #2563eb; font-size: 14pt; border-bottom: 2px solid #e5e7eb; padding-bottom: 4px; margin-top: 22px; }
        table { width: 100%; border-collapse: collapse; margin: 8px 0; }
        th, td { border: 1px solid #e5e7eb; padding: 5px 8px; font-size: 10pt; }
        th { background: #f3f4f6; }
        .section { page-break-inside: avoid; }
        .box { background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 6px; padding: 8px 12px; margin: 8px 0; font-size: 10pt; }
        .muted { color: #9ca3af; }
        .page-footer { position: fixed; bottom: 0; width: 100%; text-align: center;
            font-size: 8.5pt; color: #9ca3af; border-top: 1px solid #e5e7eb; padding-top: 4px; }
    </style>
</head>
<body>

<div class="page-footer">
    {{ $t['footer'] }} · {{ $project->title }} · v{{ $artifact->version }} · {{ $artifact->model_used?->value ?? '-' }}
</div>

<div class="cover">
    <div class="logo">إحياء</div>
    <div class="tagline">Ihyaa — {{ $t['title'] }}</div>
    <div class="project-title">{{ $project->title }}</div>
    <div class="meta">
        {{ $t['type'] }}: {{ $type }} · {{ $t['version'] }} {{ $artifact->version }}
        · {{ $t['date'] }}: {{ $artifact->created_at?->format('Y-m-d H:i') }}
        · {{ $t['model'] }}: {{ $artifact->model_used?->value ?? '-' }}
    </div>
</div>

<pagebreak />

@if (! empty($items['summary']))
    <div class="section">
        <h2>{{ $t['summary'] }}</h2>
        <div class="box">{{ $items['summary'] }}</div>
    </div>
@endif

@if ($type === 'swot')
    @foreach ([
        'strengths' => $t['strengths'],
        'weaknesses' => $t['weaknesses'],
        'opportunities' => $t['opportunities'],
        'threats' => $t['threats'],
    ] as $key => $label)
        <div class="section">
            <h2>{{ $label }}</h2>
            @forelse ($items[$key] ?? [] as $item)
                <div>• {{ $item }}</div>
            @empty
                <div class="muted">{{ $t['no_items'] }}</div>
            @endforelse
        </div>
    @endforeach
@endif

@if ($type === 'competitive')
    @foreach ([
        'competitive_advantage' => $t['competitive_advantage'],
        'differentiators' => $t['differentiators'],
        'gaps_to_address' => $t['gaps_to_address'],
    ] as $key => $label)
        <div class="section">
            <h2>{{ $label }}</h2>
            @forelse ($items[$key] ?? [] as $item)
                <div>• {{ $item }}</div>
            @empty
                <div class="muted">{{ $t['no_items'] }}</div>
            @endforelse
        </div>
    @endforeach

    @if (! empty($items['market_share']['range_usd']))
        <div class="section">
            <h2>{{ $t['market_share'] }}</h2>
            <p>
                <strong>{{ $t['range'] }}:</strong>
                ${{ number_format($items['market_share']['range_usd']['min']) }} –
                ${{ number_format($items['market_share']['range_usd']['max']) }}
                ({{ $t['share_percent'] }}: {{ $items['market_share']['share_percent'] ?? '-' }}%)
            </p>
            <h3>{{ $t['assumptions'] }}</h3>
            @forelse ($items['market_share']['assumptions'] ?? [] as $a)
                <div>• {{ $a }}</div>
            @empty
                <div class="muted">{{ $t['no_items'] }}</div>
            @endforelse
            <h3>{{ $t['limitations'] }}</h3>
            @forelse ($items['market_share']['limitations'] ?? [] as $l)
                <div>• {{ $l }}</div>
            @empty
                <div class="muted">{{ $t['no_items'] }}</div>
            @endforelse
        </div>
    @endif
@endif

@if (! empty($items['recommendations']))
    <div class="section">
        <h2>{{ $t['recommendations'] }}</h2>
        @forelse ($items['recommendations'] as $item)
            <div>• {{ $item }}</div>
        @empty
            <div class="muted">{{ $t['no_items'] }}</div>
        @endforelse
    </div>
@endif

@if ($type === 'comparison' || ! empty($items['comparison']))
    <div class="section">
        <h2>{{ $t['competitors'] }}</h2>
        @if (! empty($items['comparison']['insufficient_data_note']) && ! empty($items['comparison']['insufficient_data_note']))
            <div class="muted">{{ $t['insufficient'] }}</div>
        @endif
        @php $rows = $items['comparison']['competitors'] ?? $items['competitors'] ?? []; @endphp
        <table>
            <tr>
                <th>{{ $t['project'] }}</th>
                <th>{{ $t['score'] }}</th>
                <th>{{ $t['overlap'] }}</th>
            </tr>
            @forelse ($rows as $competitor)
                <tr>
                    <td>{{ $competitor['title'] ?? '-' }}</td>
                    <td>{{ $competitor['ai_score'] ?? '-' }}</td>
                    <td>{{ $competitor['tag_overlap'] ?? 0 }}</td>
                </tr>
            @empty
                <tr><td colspan="3" class="muted">{{ $t['no_items'] }}</td></tr>
            @endforelse
        </table>
    </div>
@endif

</body>
</html>
