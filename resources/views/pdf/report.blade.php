@php
    /** قالب تقرير AI PDF — EPIC-05 (US-028). RTL/LTR حسب lang. */
    $dir = $lang === 'ar' ? 'rtl' : 'ltr';
    $align = $lang === 'ar' ? 'right' : 'left';

    $t = $lang === 'ar' ? [
        'report' => 'تقرير التقييم الذكي',
        'project' => 'المشروع',
        'version' => 'الإصدار',
        'date' => 'تاريخ التقرير',
        'overall' => 'الدرجة الكلية',
        'confidence' => 'درجة الثقة',
        'model' => 'النموذج',
        'radar' => 'الرسم الراداري',
        'dimensions' => 'الأبعاد الخمسة',
        'dimension' => 'البعد',
        'score' => 'الدرجة',
        'sub_scores' => 'المعايير الفرعية',
        'strengths' => 'نقاط القوة',
        'weaknesses' => 'نقاط الضعف',
        'gap_analysis' => 'تحليل الفجوات',
        'technical_gaps' => 'الفجوات التقنية',
        'market_gaps' => 'الفجوات السوقية',
        'team_gaps' => 'فجوات الفريق',
        'documentation_gaps' => 'فجوات التوثيق',
        'recommendations' => 'التوصيات',
        'immediate' => 'فورية',
        'short_term' => 'قصيرة المدى',
        'long_term' => 'طويلة المدى',
        'skills' => 'المهارات المطلوبة',
        'existing_skills' => 'موجودة في الفريق',
        'missing_skills' => 'ناقصة — مطلوب توفيرها',
        'warnings' => 'تنبيهات',
        'partial_warning' => 'التقرير جزئي — الأبعاد الناقصة:',
        'footer' => 'تقرير تقييم AI — منصة إحياء',
        'no_items' => 'لا توجد عناصر',
        'dimension_labels' => [
            'technical_quality' => 'الجودة التقنية',
            'innovation' => 'الابتكار',
            'market_viability' => 'الجدوى السوقية',
            'team_completeness' => 'اكتمال الفريق',
            'documentation' => 'التوثيق',
        ],
    ] : [
        'report' => 'AI Evaluation Report',
        'project' => 'Project',
        'version' => 'Version',
        'date' => 'Report date',
        'overall' => 'Overall Score',
        'confidence' => 'Confidence',
        'model' => 'Model',
        'radar' => 'Radar Chart',
        'dimensions' => 'Dimensions',
        'dimension' => 'Dimension',
        'score' => 'Score',
        'sub_scores' => 'Sub-scores',
        'strengths' => 'Strengths',
        'weaknesses' => 'Weaknesses',
        'gap_analysis' => 'Gap Analysis',
        'technical_gaps' => 'Technical gaps',
        'market_gaps' => 'Market gaps',
        'team_gaps' => 'Team gaps',
        'documentation_gaps' => 'Documentation gaps',
        'recommendations' => 'Recommendations',
        'immediate' => 'Immediate',
        'short_term' => 'Short-term',
        'long_term' => 'Long-term',
        'skills' => 'Required Skills',
        'existing_skills' => 'Covered by team',
        'missing_skills' => 'Missing — need to be added',
        'warnings' => 'Warnings',
        'partial_warning' => 'Partial report — missing dimensions:',
        'footer' => 'AI Evaluation Report — Ihyaa Platform',
        'no_items' => 'No items',
        'dimension_labels' => [
            'technical_quality' => 'Technical Quality',
            'innovation' => 'Innovation',
            'market_viability' => 'Market Viability',
            'team_completeness' => 'Team Completeness',
            'documentation' => 'Documentation',
        ],
    ];

    $evaluation = $report['evaluation'] ?? [];
    $dimensions = $evaluation['dimensions'] ?? [];
    $gaps = $evaluation['gap_analysis'] ?? [];
    $recommendations = $evaluation['recommendations'] ?? [];
    $skills = $evaluation['required_skills'] ?? [];
    $warnings = $evaluation['warnings'] ?? [];
    $partial = $evaluation['partial_dimensions'] ?? [];
    $teamMeta = $report['team_meta'] ?? null;
    $axes = $report['radar_chart']['axes'] ?? [];
    $score = $evaluation['overall_score'] ?? $project->ai_score ?? 0;
@endphp
<!DOCTYPE html>
<html lang="{{ $lang }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <title>{{ $t['report'] }} — {{ $project->title }}</title>
    <style>
        body { font-family: inherit; color: #1f2937; font-size: 11pt; line-height: 1.6; }
        .cover { text-align: center; padding-top: 120px; }
        .logo { font-size: 30pt; font-weight: bold; color: #2563eb; }
        .tagline { color: #6b7280; font-size: 12pt; margin-top: 4px; }
        .score-badge { margin: 40px auto 0; width: 160px; height: 160px; border-radius: 50%;
            background: #2563eb; color: #fff; display: table; }
        .score-badge .inner { display: table-cell; vertical-align: middle; font-size: 40pt; font-weight: bold; }
        .score-label { margin-top: 12px; color: #6b7280; font-size: 12pt; }
        .project-title { font-size: 16pt; font-weight: bold; margin-top: 36px; }
        .meta { color: #6b7280; font-size: 10pt; margin-top: 6px; }
        h2 { color: #2563eb; font-size: 14pt; border-bottom: 2px solid #e5e7eb; padding-bottom: 4px; margin-top: 24px; }
        h3 { font-size: 11.5pt; color: #374151; margin-bottom: 4px; }
        table { width: 100%; border-collapse: collapse; margin: 8px 0; }
        th, td { border: 1px solid #e5e7eb; padding: 5px 8px; font-size: 10pt; }
        th { background: #f3f4f6; }
        .section { page-break-inside: avoid; }
        .chip { display: inline-block; background: #eff6ff; color: #1e40af;
            border: 1px solid #bfdbfe; border-radius: 4px; padding: 2px 8px; margin: 2px; font-size: 9.5pt; }
        .warning-box { background: #fef3c7; border: 1px solid #fcd34d; border-radius: 6px;
            padding: 8px 12px; margin: 10px 0; font-size: 10pt; }
        .radar-wrap { text-align: center; margin: 12px 0; }
        .page-footer { position: fixed; bottom: 0; width: 100%; text-align: center;
            font-size: 8.5pt; color: #9ca3af; border-top: 1px solid #e5e7eb; padding-top: 4px; }
    </style>
</head>
<body>

{{-- التذييل الثابت — تاريخ الإصدار + رقم التقرير + النموذج --}}
<div class="page-footer">
    {{ $t['footer'] }} · {{ $project->title }} · v{{ $evaluation['version'] ?? 1 }}
    · {{ $evaluation['model_used'] ?? '-' }}
</div>

{{-- الغلاف — الشعار + الدرجة بارزة --}}
<div class="cover">
    <div class="logo">إحياء</div>
    <div class="tagline">Ihyaa — {{ $t['report'] }}</div>

    <div class="score-badge"><div class="inner">{{ round((float) $score) }}</div></div>
    <div class="score-label">{{ $t['overall'] }} / 100</div>

    <div class="project-title">{{ $project->title }}</div>
    <div class="meta">
        {{ $t['project'] }} #{{ $project->id }} · {{ $t['version'] }} {{ $evaluation['version'] ?? '-' }}
        · {{ $t['date'] }}: {{ isset($evaluation['completed_at']) ? \Illuminate\Support\Carbon::parse($evaluation['completed_at'])->format('Y-m-d H:i') : '-' }}
    </div>
</div>

<pagebreak />

{{-- تحذير التقرير الجزئي — يُظهر قبل كل شيء --}}
@if (! empty($partial))
    <div class="warning-box">
        <strong>{{ $t['partial_warning'] }}</strong>
        @foreach ($partial as $dim)
            {{ $t['dimension_labels'][$dim] ?? $dim }}{{ ! $loop->last ? '، ' : '' }}
        @endforeach
    </div>
@endif

{{-- تنبيهات عامة --}}
@if (! empty($warnings))
    <div class="section">
        <h2>{{ $t['warnings'] }}</h2>
        @foreach ($warnings as $warning)
            <div class="warning-box">{{ $warning }}</div>
        @endforeach
    </div>
@endif

{{-- الرادار --}}
@if (! empty($axes))
    <div class="section">
        <h2>{{ $t['radar'] }}</h2>
        <div class="radar-wrap">{!! $radarSvg !!}</div>
        <table>
            <tr><th>{{ $t['dimension'] }}</th><th>{{ $t['score'] }}</th></tr>
            @foreach ($axes as $axis)
                <tr>
                    <td>{{ $t['dimension_labels'][$axis['dimension']] ?? $axis['label_ar'] }}</td>
                    <td>{{ $axis['value'] }}</td>
                </tr>
            @endforeach
        </table>
    </div>
@endif

{{-- الأبعاد الخمسة --}}
@if (! empty($dimensions))
    <div class="section">
        <h2>{{ $t['dimensions'] }}</h2>
        @foreach ($dimensions as $key => $dimension)
            <h3>{{ $t['dimension_labels'][$key] ?? $key }} — {{ $dimension['score'] ?? '-' }}</h3>

            @if (! empty($dimension['sub_scores']))
                <table>
                    <tr><th>{{ $t['sub_scores'] }}</th><th>{{ $t['score'] }}</th></tr>
                    @foreach ($dimension['sub_scores'] as $criterion => $value)
                        <tr><td>{{ $criterion }}</td><td>{{ $value }}</td></tr>
                    @endforeach
                </table>
            @endif

            @if (! empty($dimension['strengths']) || ! empty($dimension['weaknesses']))
                <table>
                    <tr>
                        <th style="width:50%">{{ $t['strengths'] }}</th>
                        <th style="width:50%">{{ $t['weaknesses'] }}</th>
                    </tr>
                    <tr>
                        <td>
                            @forelse ($dimension['strengths'] ?? [] as $s)
                                <div>• {{ $s }}</div>
                            @empty
                                —
                            @endforelse
                        </td>
                        <td>
                            @forelse ($dimension['weaknesses'] ?? [] as $w)
                                <div>• {{ $w }}</div>
                            @empty
                                —
                            @endforelse
                        </td>
                    </tr>
                </table>
            @endif
        @endforeach
    </div>
@endif

{{-- الفجوات الأربع --}}
@if (! empty($gaps))
    <div class="section">
        <h2>{{ $t['gap_analysis'] }}</h2>
        @foreach ([
            'technical_gaps' => $t['technical_gaps'],
            'market_gaps' => $t['market_gaps'],
            'team_gaps' => $t['team_gaps'],
            'documentation_gaps' => $t['documentation_gaps'],
        ] as $key => $label)
            <h3>{{ $label }}</h3>
            @forelse ($gaps[$key] ?? [] as $item)
                <div>• {{ $item }}</div>
            @empty
                <div style="color:#9ca3af">{{ $t['no_items'] }}</div>
            @endforelse
        @endforeach
    </div>
@endif

{{-- التوصيات بالآفاق الثلاثة --}}
@if (! empty($recommendations))
    <div class="section">
        <h2>{{ $t['recommendations'] }}</h2>
        @foreach ([
            'immediate' => $t['immediate'],
            'short_term' => $t['short_term'],
            'long_term' => $t['long_term'],
        ] as $key => $label)
            <h3>{{ $label }}</h3>
            @forelse ($recommendations[$key] ?? [] as $item)
                <div>• {{ $item }}</div>
            @empty
                <div style="color:#9ca3af">{{ $t['no_items'] }}</div>
            @endforelse
        @endforeach
    </div>
@endif

{{-- المهارات المطلوبة — موجودة مقابل ناقصة --}}
@if (! empty($skills) || $teamMeta)
    <div class="section">
        <h2>{{ $t['skills'] }}</h2>

        @if ($teamMeta && ! $teamMeta['has_team_data'] && ! empty($teamMeta['warning']))
            <div class="warning-box">{{ $teamMeta['warning'] }}</div>
        @endif

        @if ($teamMeta && ! empty($teamMeta['existing_skills']))
            <h3>{{ $t['existing_skills'] }}</h3>
            <div>
                @foreach ($teamMeta['existing_skills'] as $skill)
                    <span class="chip">{{ $skill }}</span>
                @endforeach
            </div>
        @endif

        @if ($teamMeta && ! empty($teamMeta['missing_skills']))
            <h3>{{ $t['missing_skills'] }}</h3>
            <div>
                @foreach ($teamMeta['missing_skills'] as $skill)
                    <span class="chip" style="background:#fef2f2;color:#b91c1c;border-color:#fecaca">{{ $skill }}</span>
                @endforeach
            </div>
        @elseif (empty($teamMeta) && ! empty($skills))
            <div>
                @foreach ($skills as $skill)
                    <span class="chip">{{ $skill }}</span>
                @endforeach
            </div>
        @endif
    </div>
@endif

</body>
</html>
