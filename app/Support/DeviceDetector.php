<?php

namespace App\Support;

class DeviceDetector
{
    /**
     * تحليل نص User-Agent واستخراج معلومات الجهاز، نظام التشغيل، والمتصفح.
     *
     * @param string|null $userAgent
     * @param string|null $explicitDeviceName
     * @return array{
     *     os: string,
     *     browser: string,
     *     device_name: string,
     *     device_type: string
     * }
     */
    public static function detect(?string $userAgent, ?string $explicitDeviceName = null): array
    {
        $ua = (string) $userAgent;

        $os = self::detectOs($ua);
        $browser = self::detectBrowser($ua);
        $deviceType = self::detectDeviceType($ua);
        $deviceName = self::detectDeviceName($ua, $explicitDeviceName, $os, $deviceType);

        return [
            'os' => $os,
            'browser' => $browser,
            'device_name' => $deviceName,
            'device_type' => $deviceType,
        ];
    }

    private static function detectOs(string $ua): string
    {
        if (preg_match('/iPhone|iPad|iPod/i', $ua)) {
            if (preg_match('/OS (\d+[_.\d]*)/i', $ua, $matches)) {
                $version = str_replace('_', '.', $matches[1]);
                return 'iOS ' . $version;
            }
            return 'iOS';
        }

        if (preg_match('/Android/i', $ua)) {
            if (preg_match('/Android (\d+[\.\d]*)/i', $ua, $matches)) {
                return 'Android ' . $matches[1];
            }
            return 'Android';
        }

        if (preg_match('/Windows NT 10\.0/i', $ua)) {
            // Windows 11 و Windows 10 يشتركان في NT 10.0 في سلاسل User Agent
            return 'Windows 11';
        }

        if (preg_match('/Windows NT 6\.3/i', $ua)) {
            return 'Windows 8.1';
        }

        if (preg_match('/Windows NT 6\.2/i', $ua)) {
            return 'Windows 8';
        }

        if (preg_match('/Windows NT 6\.1/i', $ua)) {
            return 'Windows 7';
        }

        if (preg_match('/Windows/i', $ua)) {
            return 'Windows';
        }

        if (preg_match('/Macintosh|Mac OS X/i', $ua)) {
            if (preg_match('/Mac OS X (\d+[_\d]*)/i', $ua, $matches)) {
                $version = str_replace('_', '.', $matches[1]);
                return 'macOS ' . $version;
            }
            return 'macOS';
        }

        if (preg_match('/Ubuntu/i', $ua)) {
            return 'Ubuntu Linux';
        }

        if (preg_match('/Linux/i', $ua)) {
            return 'Linux';
        }

        return 'نظام غير معروف';
    }

    private static function detectBrowser(string $ua): string
    {
        if (preg_match('/PostmanRuntime/i', $ua)) {
            return 'Postman';
        }

        if (preg_match('/IhyaaApp/i', $ua)) {
            return 'تطبيق إحياء';
        }

        if (preg_match('/Edg(?:e)?\/(\d+[\.\d]*)/i', $ua, $matches)) {
            return 'Edge ' . explode('.', $matches[1])[0];
        }

        if (preg_match('/OPR\/(\d+[\.\d]*)/i', $ua, $matches) || preg_match('/Opera/i', $ua)) {
            return 'Opera';
        }

        if (preg_match('/Chrome\/(\d+[\.\d]*)/i', $ua, $matches)) {
            return 'Chrome ' . explode('.', $matches[1])[0];
        }

        if (preg_match('/Version\/(\d+[\.\d]*).*Safari/i', $ua, $matches)) {
            return 'Safari ' . explode('.', $matches[1])[0];
        }

        if (preg_match('/Safari/i', $ua) && !preg_match('/Chrome/i', $ua)) {
            return 'Safari';
        }

        if (preg_match('/Firefox\/(\d+[\.\d]*)/i', $ua, $matches)) {
            return 'Firefox ' . explode('.', $matches[1])[0];
        }

        return 'متصفح غير معروف';
    }

    private static function detectDeviceType(string $ua): string
    {
        if (preg_match('/iPad|tablet|PlayBook/i', $ua)) {
            return 'tablet';
        }

        if (preg_match('/Mobile|iPhone|iPod|Android.*Mobile|BlackBerry|IEMobile|Opera Mini/i', $ua)) {
            return 'mobile';
        }

        if (preg_match('/Windows|Macintosh|Linux/i', $ua)) {
            return 'desktop';
        }

        return 'unknown';
    }

    private static function detectDeviceName(
        string $ua,
        ?string $explicitDeviceName,
        string $os,
        string $deviceType
    ): string {
        if (!empty($explicitDeviceName) && $explicitDeviceName !== 'api') {
            return $explicitDeviceName;
        }

        if (preg_match('/iPhone/i', $ua)) {
            return 'iPhone';
        }

        if (preg_match('/iPad/i', $ua)) {
            return 'iPad';
        }

        if (preg_match('/Android/i', $ua)) {
            if (preg_match('/;\s*([^;]+)\s+Build/i', $ua, $matches)) {
                $model = trim($matches[1]);
                return $model ?: 'Android Device';
            }
            return 'Android Device';
        }

        if (str_starts_with($os, 'Windows')) {
            return 'Windows PC';
        }

        if (str_starts_with($os, 'macOS')) {
            return 'Mac';
        }

        if (str_starts_with($os, 'Linux') || str_starts_with($os, 'Ubuntu')) {
            return 'Linux PC';
        }

        return 'جهاز غير معروف';
    }
}
