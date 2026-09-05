<?php

namespace App\Jobs;

use App\Enums\UserRole;
use App\Models\Evaluation;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * تنبيه المشرف عند فشل جميع المزوّدين — plan.md §3.2 (FR-222 · SRS-AI-F04).
 *
 * القناة `notifications` · tries=3 · timeout=30s.
 * ينشئ إشعاراً لكل مشرف (role=admin — لا تسجيل عام) + سطر Log للمعايرة.
 *
 * لا يُرسل أي خطأ تقني خام إلى مالك المشروع — يبقى الخطأ الكامل في error_log
 * للمعايرة، والمستخدم يرى فقط "فشل تقييم مشروعك — يمكنك إعادة المحاولة"
 * (SRS-AI-F04 · الدستور المبدأ V).
 */
class NotifyAdminAiOutage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        public Evaluation $evaluation,
        public array $failures = [],
    ) {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        $project = $this->evaluation->project;

        if (! $project) {
            return;
        }

        $admins = User::query()->where('role', UserRole::ADMIN)->get();

        foreach ($admins as $admin) {
            Notification::pushNotification(
                (int) $admin->id,
                'ai_outage',
                'تعذّر تقييم AI — فشل جميع المزوّدين',
                null,
                [
                    'project_id' => $project->id,
                    'project_title' => $project->title,
                    'evaluation_id' => $this->evaluation->id,
                    'provider_count' => count($this->failures),
                    'status' => 'all_providers_failed',
                    'url' => '/projects/'.$project->id,
                ],
                isCritical: false,
            );
        }

        Log::error('All AI providers failed for evaluation #'.$this->evaluation->id, [
            'project_id' => $project->id,
            'failures' => collect($this->failures)
                ->map(fn ($failure) => [
                    'provider' => method_exists($failure, 'provider') ? $failure->provider() : null,
                    'reason' => method_exists($failure, 'reason') ? $failure->reason() : $failure->getMessage(),
                    'attempt' => method_exists($failure, 'attempt') ? $failure->attempt() : null,
                ])
                ->all(),
        ]);
    }
}
