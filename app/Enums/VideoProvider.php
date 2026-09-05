<?php

namespace App\Enums;

/**
 * مزود الفيديو — docs/api/enums.md §1.9: YouTube/Vimeo فقط (URL Validation).
 */
enum VideoProvider: string
{
    case YOUTUBE = 'youtube';
    case VIMEO = 'vimeo';

    public function label(): string
    {
        return match ($this) {
            self::YOUTUBE => 'YouTube',
            self::VIMEO => 'Vimeo',
        };
    }
}
