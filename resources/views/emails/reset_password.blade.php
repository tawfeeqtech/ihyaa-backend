@extends('emails.layout')

@section('content')
<table border="0" cellpadding="0" cellspacing="0" width="100%" style="direction: rtl; text-align: right;">
    <tr>
        <td>
            <h2 style="margin: 0 0 16px 0; color: #010C23; font-size: 22px; font-weight: 800; font-family: 'Cairo', Tahoma, sans-serif;">
                إعادة تعيين كلمة المرور
            </h2>

            <p style="margin: 0 0 14px 0; color: #374151; font-size: 15px; line-height: 1.8; font-family: 'Cairo', Tahoma, sans-serif;">
                مرحباً <strong>{{ $userName ?? 'بك' }}</strong>،
            </p>

            <p style="margin: 0 0 26px 0; color: #4b5563; font-size: 14px; line-height: 1.8; font-family: 'Cairo', Tahoma, sans-serif;">
                لقد تلقينا طلباً لإعادة تعيين كلمة المرور الخاصة بحسابك في <strong>منصة إحياء</strong>. يمكنك تعيين كلمة مرور جديدة من خلال الضغط على الزر أدناه:
            </p>

            <!-- Reset Button CTA -->
            <table border="0" cellpadding="0" cellspacing="0" width="100%" style="margin: 28px 0 24px 0;">
                <tr>
                    <td align="center">
                        <table border="0" cellpadding="0" cellspacing="0">
                            <tr>
                                <td align="center" style="background-color: #9E7F4D; border-radius: 10px; box-shadow: 0 6px 16px rgba(158, 127, 77, 0.35);">
                                    <a href="{{ $resetUrl }}" target="_blank" style="display: inline-block; padding: 15px 36px; font-family: 'Cairo', Tahoma, sans-serif; font-size: 16px; font-weight: 700; color: #ffffff; text-decoration: none; border-radius: 10px; border: 1px solid #b5945e; letter-spacing: 0.5px;">
                                        إعادة تعيين كلمة المرور ←
                                    </a>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>

            <!-- Timer Badge -->
            <table border="0" cellpadding="0" cellspacing="0" width="100%" style="margin: 0 0 24px 0;">
                <tr>
                    <td align="center">
                        <span style="display: inline-block; background-color: #fef3c7; color: #92400e; font-size: 13px; font-weight: 700; padding: 6px 16px; border-radius: 20px; border: 1px solid #fde68a; font-family: 'Cairo', Tahoma, sans-serif;">
                            ⏳ هذا الرابط صالح لمدة <strong>60 دقيقة</strong> فقط
                        </span>
                    </td>
                </tr>
            </table>

            <!-- Fallback URL -->
            <div style="background-color: #f8fafc; border-radius: 10px; padding: 14px 18px; margin: 20px 0; border: 1px dashed #cbd5e1;">
                <p style="margin: 0 0 6px 0; font-size: 12px; color: #64748b; font-family: 'Cairo', Tahoma, sans-serif;">
                    إذا واجهت مشكلة في الضغط على الزر، يمكنك نسخ الرابط التالي ولصقه في متصفحك مباشرة:
                </p>
                <p style="margin: 0; font-size: 11px; word-break: break-all; color: #9E7F4D; direction: ltr; text-align: left; font-family: 'Courier New', Courier, monospace;">
                    <a href="{{ $resetUrl }}" target="_blank" style="color: #9E7F4D; text-decoration: underline;">
                        {{ $resetUrl }}
                    </a>
                </p>
            </div>

            <p style="margin: 20px 0 0 0; color: #6b7280; font-size: 13px; line-height: 1.8; font-family: 'Cairo', Tahoma, sans-serif;">
                إذا لم تكن أنت من طلب إعادة تعيين كلمة المرور، يمكنك تجاهل هذه الرسالة بأمان وستظل كلمة مرورك الحالية كما هي دون أي تغيير.
            </p>
        </td>
    </tr>
</table>
@endsection
