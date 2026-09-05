{{-- T049 — قالب مستند الاتفاق الثابت PDF (US-045 · FR-310) — RTL عربي.
     بلا توقيع/عناصر تفاعلية (القانون الكامل مؤجل v2.0+ — وثيقة ثابتة بأسماء الطرفين
     + رقم الطلب + معرف/عنوان المشروع + التاريخ). يُستدعى من AgreementPdfGenerator (T050). --}}
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="utf-8">
    <title>{{ __('agreement.pdf_title', ['app' => config('app.name', 'Ihyaa')]) }}</title>
    <style>
        * { font-family: amiri, dejavusans, sans-serif; }
        body {
            font-size: 14px;
            line-height: 1.9;
            color: #1f2937;
            direction: rtl;
            text-align: right;
        }
        .header {
            text-align: center;
            border-bottom: 3px solid #0d9488;
            padding-bottom: 12px;
            margin-bottom: 24px;
        }
        .header h1 {
            font-size: 24px;
            color: #0d9488;
            margin: 0 0 4px;
        }
        .header p { margin: 0; color: #6b7280; font-size: 13px; }
        .meta { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
        .meta td {
            border: 1px solid #e5e7eb;
            padding: 10px 14px;
        }
        .meta .label {
            width: 34%;
            background: #f0fdfa;
            font-weight: bold;
            color: #115e59;
        }
        .body { margin-bottom: 28px; text-align: justify; }
        .body h2 {
            font-size: 17px;
            color: #0f766e;
            border-right: 5px solid #0d9488;
            padding-right: 10px;
        }
        .footer {
            border-top: 1px solid #e5e7eb;
            padding-top: 12px;
            text-align: center;
            color: #6b7280;
            font-size: 12px;
        }
    </style>
</head>
<body>

    <div class="header">
        <h1>{{ __('agreement.document_title', ['app' => config('app.name', 'Ihyaa')]) }}</h1>
        <p>{{ __('agreement.document_subtitle') }}</p>
    </div>

    <table class="meta">
        <tr>
            <td class="label">{{ __('agreement.interest_id') }}</td>
            <td>#{{ $interest->id }}</td>
        </tr>
        <tr>
            <td class="label">{{ __('agreement.project') }}</td>
            <td>{{ $project->title }} <span style="color:#6b7280">(ID: {{ $project->id }})</span></td>
        </tr>
        <tr>
            <td class="label">{{ __('agreement.idea_owner') }}</td>
            <td>{{ $owner->name }}</td>
        </tr>
        <tr>
            <td class="label">{{ __('agreement.investor') }}</td>
            <td>{{ $investor->name }}</td>
        </tr>
        <tr>
            <td class="label">{{ __('agreement.date') }}</td>
            <td>{{ now()->format('Y-m-d') }}</td>
        </tr>
    </table>

    <div class="body">
        <h2>{{ __('agreement.agreement_text_title') }}</h2>
        <p>
            {{ __('agreement.agreement_paragraph', [
                'owner' => $owner->name,
                'investor' => $investor->name,
                'project' => $project->title,
            ]) }}
        </p>
        <p>{{ __('agreement.legal_note') }}</p>
    </div>

    <div class="footer">
        {{ __('agreement.generated_by', ['app' => config('app.name', 'Ihyaa')]) }} — {{ now()->format('Y-m-d H:i') }}
    </div>

</body>
</html>
