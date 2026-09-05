<?php

namespace App\Console\Commands;

use Database\Seeders\BenchmarkProjectSeeder;
use Illuminate\Console\Command;

/**
 * استيراد بيانات تجريبية للبحث — US-030 (T119).
 *
 * الأمر: `projects:import-demo` [--count=1000]
 *
 * يستدعي BenchmarkProjectSeeder لإنشاء 1000 مشروع منشور مختلط (عربي/إنجليزي)
 * مع تقييمات — لاختبار أداء البحث (P95 ≤ 200ms على 1000 وثيقة).
 */
class ImportDemoProjects extends Command
{
    protected $signature = 'projects:import-demo {--count=1000 : عدد المشاريع}';

    protected $description = 'استيراد بيانات تجريبية (عربي/إنجليزي) لاختبار أداء البحث';

    public function handle(): int
    {
        $count = max(1, min((int) $this->option('count'), 100000));

        $this->warn("جارٍ إنشاء {$count} مشروعاً تجريبياً وفهرستها في Meilisearch — قد يستغرق دقيقة...");

        (new BenchmarkProjectSeeder())->run();

        $this->info('اكتمل الاستيراد التجريبي. شغّل meilisearch:sync-settings ثم search:rebuild عند الحاجة.');

        return self::SUCCESS;
    }
}
