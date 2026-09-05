<?php

namespace App\Services\Disclosure;

/**
 * سياق الإفصاح المحقون في الطلب بواسطة EnforceReportDisclosure middleware
 * (app/Http/Middleware/EnforceReportDisclosure.php) — يقرأه المتحكم بدل إعادة
 * الحساب (نقطة فرض واحدة).
 *
 * @immutable
 */
final class DisclosureContext
{
    public function __construct(
        public readonly DisclosureLevel $level,
        public readonly bool $isOwner,
        public readonly bool $canViewFull,
    ) {
    }

    public static function from(DisclosureLevel $level, bool $isOwner): self
    {
        return new self(
            level: $level,
            isOwner: $isOwner,
            canViewFull: $level->canViewFull(),
        );
    }
}
