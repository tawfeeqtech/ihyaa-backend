<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>منصة إحياء</title>
    <!--[if mso]>
    <style type="text/css">
        body, table, td, p, a, h1, h2, h3, span { font-family: Tahoma, Arial, sans-serif !important; }
    </style>
    <![endif]-->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style type="text/css">
        body {
            margin: 0;
            padding: 0;
            background-color: #0b1528;
            font-family: 'Cairo', -apple-system, BlinkMacSystemFont, 'Segoe UI', Tahoma, Arial, sans-serif;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            direction: rtl;
            text-align: right;
            color: #1F2937;
        }
        table {
            border-collapse: collapse;
        }
        img {
            border: 0;
            line-height: 100%;
            outline: none;
            text-decoration: none;
            display: block;
        }
        @media only screen and (max-width: 620px) {
            .email-container {
                width: 100% !important;
                margin: 0 auto !important;
                border-radius: 0 !important;
            }
            .content-padding {
                padding: 24px 18px !important;
            }
            .header-padding {
                padding: 24px 18px !important;
            }
            .otp-code {
                font-size: 28px !important;
                letter-spacing: 6px !important;
            }
        }
    </style>
</head>
<body style="margin: 0; padding: 30px 10px; background-color: #081120; font-family: 'Cairo', -apple-system, BlinkMacSystemFont, 'Segoe UI', Tahoma, Arial, sans-serif; direction: rtl; text-align: right;">

    <!-- Background Table -->
    <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #081120; direction: rtl;">
        <tr>
            <td align="center">
                <!-- Main Container -->
                <table class="email-container" border="0" cellpadding="0" cellspacing="0" width="600" style="max-width: 600px; width: 100%; background-color: #ffffff; border-radius: 18px; overflow: hidden; box-shadow: 0 15px 35px rgba(0, 0, 0, 0.35); border: 1px solid rgba(158, 127, 77, 0.3);">
                    
                    <!-- Header -->
                    <tr>
                        <td class="header-padding" align="center" style="background-color: #010C23; padding: 36px 30px 28px 30px; border-bottom: 3px solid #9E7F4D;">
                            <table border="0" cellpadding="0" cellspacing="0" width="100%">
                                <tr>
                                    <td align="center">
                                        @if(file_exists(public_path('logo.jpeg')))
                                            <img src="{{ $message->embed(public_path('logo.jpeg')) }}" alt="شعار منصة إحياء" width="110" style="max-width: 110px; height: auto; border-radius: 10px; margin-bottom: 12px; border: 1px solid #9E7F4D;">
                                        @endif
                                        <h1 style="margin: 6px 0 0 0; color: #ffffff; font-size: 22px; font-weight: 800; font-family: 'Cairo', Tahoma, sans-serif; letter-spacing: 0.5px;">
                                            منصة إحياء
                                        </h1>
                                        <p style="margin: 4px 0 0 0; color: #9E7F4D; font-size: 13px; font-weight: 600; letter-spacing: 1px;">
                                            IHYAA PLATFORM
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Body Content -->
                    <tr>
                        <td class="content-padding" style="padding: 38px 40px; background-color: #ffffff; color: #1f2937;">
                            @yield('content')
                        </td>
                    </tr>

                    <!-- Divider -->
                    <tr>
                        <td style="padding: 0 40px;">
                            <hr style="border: none; border-top: 1px solid #f0ede6; margin: 0;">
                        </td>
                    </tr>

                    <!-- Security Alert Notice -->
                    <tr>
                        <td style="padding: 20px 40px; background-color: #faf8f5;">
                            <table border="0" cellpadding="0" cellspacing="0" width="100%">
                                <tr>
                                    <td style="font-size: 12px; color: #78716c; line-height: 1.7; text-align: right; font-family: 'Cairo', Tahoma, sans-serif;">
                                        <strong style="color: #9E7F4D;">🔒 تنبيه أمني:</strong> لا تشارك هذا الرمز أو الرابط مع أي شخص أبداً. فريق منصة إحياء لن يطلب منك كلمة المرور أو رمز التحقق بأي وسيلة.
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td align="center" style="background-color: #010C23; padding: 26px 20px; border-top: 2px solid #9E7F4D; color: #ffffff;">
                            <p style="margin: 0; color: #e2e8f0; font-size: 13px; font-weight: 600; font-family: 'Cairo', Tahoma, sans-serif;">
                                جميع الحقوق محفوظة © {{ date('Y') }} منصة إحياء
                            </p>
                            <p style="margin: 6px 0 0 0; color: #94a3b8; font-size: 11px; font-family: 'Cairo', Tahoma, sans-serif;">
                                هذه رسالة آلية تم إرسالها إليك عبر نظام منصة إحياء، يُرجى عدم الرد المباشر على هذا البريد.
                            </p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>

</body>
</html>
