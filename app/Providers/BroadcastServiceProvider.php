<?php

namespace App\Providers;

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;

class BroadcastServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * مسار مصادقة القنوات الخاصة — T074 · EPIC-09 (US-048).
     * الواجهة SPA بالكامل بلا جلسات (Sanctum Bearer tokens)، لذا نُعرّض نقطة
     * /api/broadcasting/auth محمية بـ auth:sanctum بدل /broadcasting/auth الافتراضي
     * الذي يعتمد على الـ session + CSRF (لا يصلح لعميل Echo القائم على التوكن).
     */
    public function boot(): void
    {
        Broadcast::routes([
            'prefix' => 'api/broadcasting',
            'middleware' => ['auth:sanctum'],
        ]);

        require base_path('routes/channels.php');
    }
}
