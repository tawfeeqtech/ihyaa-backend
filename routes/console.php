<?php

use App\Console\Commands\PurgeTrashedProjects;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| الأوامر المجدولة — SRS-F02-06 · docs/api/routes.md §6.7
|--------------------------------------------------------------------------
*/

// الحذف النهائي لمشاريع سلة المهملات بعد 30 يوماً (يومياً)
Schedule::command(PurgeTrashedProjects::class)->daily();

// تنظيف توكنات Sanctum المنتهية (يومياً)
Schedule::command('sanctum:prune-expired --hours=24')->daily();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
