<?php

namespace App\Http\Controllers\Api;

use App\Models\Agreement;
use App\Services\Agreement\AgreementAccessGuard;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * مستند الاتفاق — SRS-API-27 (RL-SH-04 · 10/دقيقة).
 * الرؤية: طرفا الاتفاق فقط + الأدمن (AgreementAccessGuard / AgreementPolicy — الدستور §V).
 *
 * يُمرَّر {agreement} برقم سجل الاتفاق (agreements.id) — يطابق pdf_url الصادر من
 * InterestResource ولوحة المستثمر. الإصلاح: كان يُربط بجدول interests خطأً (500
 * عند حذف المشروع ناعماً + خلل في المطابقة) — T028/agreements.
 */
class AgreementController
{
    use ApiResponse;

    /** يُمرَّر كـ {agreement} في المسار — ربط صريح بجدول agreements */
    public function show(Request $request, Agreement $agreement): BinaryFileResponse
    {
        // الطرفان فقط + الأدمن (403 FORBIDDEN + سجل أمني لغير المصرّح).
        app(AgreementAccessGuard::class)->assertAccess($request->user(), $agreement);

        // الملف موجود فقط بعد نجاح توليد PDF (لا سجل اتفاق بلا ملف).
        if (! $agreement->pdf_path) {
            abort(404, __('errors.not_found'));
        }

        $path = Storage::disk('public')->path($agreement->pdf_path);

        if (! file_exists($path)) {
            abort(404, __('errors.not_found'));
        }

        return response()->file($path, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="agreement-'.$agreement->id.'.pdf"',
        ]);
    }
}
