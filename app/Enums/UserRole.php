<?php

namespace App\Enums;

/**
 * دور المستخدم — docs/api/enums.md §1.1.
 * قابل للتعديل مرة واحدة فقط عندما يكون null (أول دخول OAuth — SRS-F01-07).
 */
enum UserRole: string
{
    case IDEA_OWNER = 'idea_owner';
    case INVESTOR = 'investor';
    case ADMIN = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::IDEA_OWNER => 'صاحب فكرة',
            self::INVESTOR => 'مستثمر',
            self::ADMIN => 'مشرف',
        };
    }

    public function isIdeaOwner(): bool
    {
        return $this === self::IDEA_OWNER;
    }

    public function isInvestor(): bool
    {
        return $this === self::INVESTOR;
    }

    public function isAdmin(): bool
    {
        return $this === self::ADMIN;
    }
}
