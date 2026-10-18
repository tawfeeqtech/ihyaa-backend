@extends('emails.layout')

@section('content')
<table border="0" cellpadding="0" cellspacing="0" width="100%" style="direction: rtl; text-align: right;">
    <tr>
        <td>
            <h2 style="margin: 0 0 16px 0; color: #010C23; font-size: 22px; font-weight: 800; font-family: 'Cairo', Tahoma, sans-serif;">
                رمز التحقق من البريد الإلكتروني
            </h2>

            <p style="margin: 0 0 14px 0; color: #374151; font-size: 15px; line-height: 1.8; font-family: 'Cairo', Tahoma, sans-serif;">
                مرحباً <strong>{{ $userName ?? 'بك' }}</strong>،
            </p>

            <p style="margin: 0 0 26px 0; color: #4b5563; font-size: 14px; line-height: 1.8; font-family: 'Cairo', Tahoma, sans-serif;">
                شكراً لتسجيلك في <strong>منصة إحياء</strong>. لإتمام عملية تفعيل حسابك، يُرجى إدخال رمز التحقق (OTP) التالي في التطبيق:
            </p>

            <!-- OTP Code Card -->
            <table border="0" cellpadding="0" cellspacing="0" width="100%" style="margin: 24px 0 28px 0;">
                <tr>
                    <td align="center">
                        <div style="background-color: #010C23; border: 2px solid #9E7F4D; border-radius: 14px; padding: 22px 30px; text-align: center; max-width: 320px; box-shadow: 0 8px 20px rgba(1, 12, 35, 0.15);">
                            <span style="display: block; color: #9E7F4D; font-size: 12px; font-weight: 700; margin-bottom: 8px; letter-spacing: 1.5px; font-family: 'Cairo', Tahoma, sans-serif;">
                                كود التحقق
                            </span>
                            <span class="otp-code" style="color: #f7e6c4; font-size: 36px; font-weight: 800; letter-spacing: 10px; font-family: 'Courier New', Courier, monospace, Tahoma; display: inline-block; direction: ltr;">
                                {{ $code }}
                            </span>
                        </div>
                    </td>
                </tr>
            </table>

            <!-- Timer Badge -->
            <table border="0" cellpadding="0" cellspacing="0" width="100%" style="margin: 0 0 20px 0;">
                <tr>
                    <td align="center">
                        <span style="display: inline-block; background-color: #fef3c7; color: #92400e; font-size: 13px; font-weight: 700; padding: 6px 16px; border-radius: 20px; border: 1px solid #fde68a; font-family: 'Cairo', Tahoma, sans-serif;">
                            ⏳ هذا الرمز صالح لمدة <strong>7 دقائق</strong> فقط
                        </span>
                    </td>
                </tr>
            </table>

            <p style="margin: 20px 0 0 0; color: #6b7280; font-size: 13px; line-height: 1.8; font-family: 'Cairo', Tahoma, sans-serif;">
                إذا لم تكن قد طلبت إنشاء حساب أو إعادة إرسال رمز التحقق، فلا داعي للقلق؛ يمكنك تجاهل هذا البريد ولن يتم تفعيل أي إجراء على حسابك.
            </p>
        </td>
    </tr>
</table>
@endsection
