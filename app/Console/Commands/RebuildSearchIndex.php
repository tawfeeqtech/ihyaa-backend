<?php

namespace App\Console\Commands;

use App\Models\Project;
use Illuminate\Console\Command;
use Throwable;

/**
 * إعادة بناء فهرس البحث بالكامل — data-model §8.4 · US-034 (T129).
 *
 * الأمر: `search:rebuild`
 *
 * المسار: flush الفهرس → إعادة فهرسة كل المشاريع المنشورة وغير المحذوفة
 * (قاعدة FR-247 — نفسها في ProjectObserver::shouldIndex) على دفعات 500.
 * يُستخدم للتعافي اليدوي بعد انقطاع Meilisearch أو لملء الفهرس في CI/الإنتاج.
 */
class RebuildSearchIndex extends Command
{
    protected $signature = 'search:rebuild {--flush : تجاهل الفلاش المسبق (أضف فقط)}';

    protected $description = 'إعادة بناء فهرس البحث: مسح ثم فهرسة كل المشاريع المنشورة غير المحذوفة';

    public function handle(): int
    {
        try {
            if (! $this->option('flush')) {
                Project::removeAllFromSearch();
                $this->info('تم مسح الفهرس (flush).');
            }
        } catch (Throwable $e) {
            $this->warn('تعذّر مسح الفهرس: '.$e->getMessage().' — نكمل بالإضافة.');

            return self::FAILURE;
        }

        $indexed = 0;

        try {
            Project::query()
                ->published()
                ->orderBy('id')
                ->chunkById(500, function ($projects) use (&$indexed) {
                    $projects->searchable();
                    $indexed += $projects->count();
                    $this->info("تمت فهرسة {$indexed} مشروع...");
                });
        } catch (Throwable $e) {
            $this->error('فشل إعادة بناء الفهرس: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("اكتملت إعادة بناء الفهرس — {$indexed} مشروع منشور مفهرس.");

        return self::SUCCESS;
    }
}
