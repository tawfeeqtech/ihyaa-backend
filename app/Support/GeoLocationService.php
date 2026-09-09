<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class GeoLocationService
{
    public const DEFAULT_LOCATION = 'غزة، فلسطين';

    /**
     * استخراج العنوان والموقع الجغرافي بناءً على الطلب وعنوان IP.
     */
    public static function resolve(Request $request): string
    {
        // 1. فحص الترويسة المخصصة المرسلة من العميل أو البوابة
        if ($headerLocation = $request->header('X-User-Location')) {
            return trim($headerLocation);
        }

        // 2. فحص ترويسات Cloudflare في بيئة الإنتاج
        $city = $request->header('CF-IPCity');
        $country = $request->header('CF-IPCountry');
        if ($city && $country) {
            return "{$city}، {$country}";
        }
        if ($country) {
            return $country === 'PS' ? 'فلسطين' : $country;
        }

        $ip = $request->ip();

        // 3. العناوين المحلية / الداخلية
        if (self::isLocalOrPrivateIp($ip)) {
            return self::DEFAULT_LOCATION;
        }

        // 4. استعلام العنوان العام مع التخزين المؤقت
        return self::resolveFromPublicIp((string) $ip);
    }

    /**
     * استعلام الموقع عبر عنوان IP عام مع التخزين المؤقت وتجنب إبطاء الطلبات.
     */
    public static function resolveFromPublicIp(string $ip): string
    {
        if (self::isLocalOrPrivateIp($ip)) {
            return self::DEFAULT_LOCATION;
        }

        return Cache::remember("geoip:{$ip}", now()->addDays(7), function () use ($ip) {
            try {
                $response = Http::timeout(1)->get("http://ip-api.com/json/{$ip}?fields=status,country,city,countryCode");

                if ($response->successful()) {
                    $data = $response->json();
                    if (($data['status'] ?? '') === 'success') {
                        $city = $data['city'] ?? '';
                        $country = $data['country'] ?? '';
                        if ($city && $country) {
                            return "{$city}، {$country}";
                        }
                        return $country ?: self::DEFAULT_LOCATION;
                    }
                }
            } catch (Throwable) {
                // في حال انقطاع الاتصال أو بطء الشبكة نعود للقيمة الافتراضية
            }

            return self::DEFAULT_LOCATION;
        });
    }

    /**
     * التحقق إن كان عنوان IP محلياً أو خاصاً.
     */
    public static function isLocalOrPrivateIp(?string $ip): bool
    {
        if (empty($ip) || in_array($ip, ['127.0.0.1', '::1', 'localhost'], true)) {
            return true;
        }

        return !filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }
}
