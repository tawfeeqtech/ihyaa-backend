<?php

namespace App\Listeners;

use App\Events\AllAiProvidersFailed;
use App\Jobs\NotifyAdminAiOutage;

/**
 * مستمع فشل جميع المزوّدين — يفوض العمل إلى Job (NotifyAdminAiOutage) — plan.md §3.2 (FR-222).
 * (نفس نمط SendEvaluationNotification → NotifyEvaluationCompleted.)
 */
class NotifyAdminAiOutageListener
{
    public function handleAllAiProvidersFailed(AllAiProvidersFailed $event): void
    {
        NotifyAdminAiOutage::dispatch($event->evaluation, $event->failures);
    }
}
