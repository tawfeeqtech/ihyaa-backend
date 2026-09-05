<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| قنوات البث — Reverb (أحداث حرجة فقط · docs/api/enums.md §2.9)
| قناة خاصة: private-users.{user_id} — مصادقة الاتصال عبر توكن Sanctum.
|--------------------------------------------------------------------------
*/

Broadcast::channel('private-users.{userId}', fn (User $user, int $userId) => (int) $user->id === $userId);

// قناة الإشعارات الحرجة — T003 · T072 · EPIC-09 (US-048).
// الحدث CriticalNotificationBroadcast ينشئ قناة private-notifications.{user_id}
// (PrivateChannel('notifications.{id}') → البادئة private- تُضاف تلقائياً)،
// لذا نعرّف القناة باسم `notifications.{userId}` بدون البادئة.
Broadcast::channel('notifications.{userId}', fn (User $user, int $userId) => (int) $user->id === $userId);
