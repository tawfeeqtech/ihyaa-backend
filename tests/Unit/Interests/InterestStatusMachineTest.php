<?php

namespace Tests\Unit\Interests;

use App\Enums\InterestStatus;
use App\Services\InterestStatusMachine;

/**
 * InterestStatusMachine — T088 · US-041..046 (SRS-DB-06).
 *
 * آلة حالات خالصة (بلا قاعدة بيانات) — كل انتقالات الحالة لكل دور:
 *   pending → accepted/rejected (صاحب الفكرة) · pending/accepted → cancelled (المستثمر)
 *   rejected/cancelled حالات نهائية · لا إعادة معالجة من نفس الحالة · مشرف = الطرفان.
 */

/** اختصار: هل الانتقال صالح؟ */
function allows(InterestStatus $from, InterestStatus $to, string $role): bool
{
    return app(InterestStatusMachine::class)->canTransition($from, $to, $role);
}

$owner = InterestStatusMachine::ROLE_OWNER;
$investor = InterestStatusMachine::ROLE_INVESTOR;
$admin = InterestStatusMachine::ROLE_ADMIN;
$system = InterestStatusMachine::ROLE_SYSTEM;

it('allows the idea owner to accept or reject a pending request', function () use ($owner) {
    expect(allows(InterestStatus::PENDING, InterestStatus::ACCEPTED, $owner))->toBeTrue();
    expect(allows(InterestStatus::PENDING, InterestStatus::ACCEPTED_PENDING_DOCUMENT, $owner))->toBeTrue();
    expect(allows(InterestStatus::PENDING, InterestStatus::REJECTED, $owner))->toBeTrue();
});

it('forbids the idea owner from cancelling a pending request', function () use ($owner) {
    expect(allows(InterestStatus::PENDING, InterestStatus::CANCELLED, $owner))->toBeFalse();
});

it('allows the investor to cancel a pending request (UC-07 E2)', function () use ($investor) {
    expect(allows(InterestStatus::PENDING, InterestStatus::CANCELLED, $investor))->toBeTrue();
    expect(allows(InterestStatus::ACCEPTED, InterestStatus::CANCELLED, $investor))->toBeTrue();
    expect(allows(InterestStatus::ACCEPTED_PENDING_DOCUMENT, InterestStatus::CANCELLED, $investor))->toBeTrue();
});

it('forbids the investor from accepting or rejecting', function () use ($investor) {
    expect(allows(InterestStatus::PENDING, InterestStatus::ACCEPTED, $investor))->toBeFalse();
    expect(allows(InterestStatus::PENDING, InterestStatus::REJECTED, $investor))->toBeFalse();
});

it('treats rejected and cancelled as terminal states (US-044 scenario 4/5)', function () use ($owner, $investor, $admin) {
    expect(allows(InterestStatus::REJECTED, InterestStatus::ACCEPTED, $owner))->toBeFalse();
    expect(allows(InterestStatus::REJECTED, InterestStatus::CANCELLED, $investor))->toBeFalse();
    expect(allows(InterestStatus::REJECTED, InterestStatus::CANCELLED, $admin))->toBeFalse();

    expect(allows(InterestStatus::CANCELLED, InterestStatus::ACCEPTED, $owner))->toBeFalse();
    expect(allows(InterestStatus::CANCELLED, InterestStatus::ACCEPTED, $admin))->toBeFalse();
    expect(allows(InterestStatus::CANCELLED, InterestStatus::PENDING, $investor))->toBeFalse();
});

it('rejects re-processing from the same state (accepted → accepted)', function () use ($owner) {
    expect(allows(InterestStatus::ACCEPTED, InterestStatus::ACCEPTED, $owner))->toBeFalse();
    expect(allows(InterestStatus::PENDING, InterestStatus::PENDING, $owner))->toBeFalse();
});

it('grants the admin every transition allowed to either party (دستور §V)', function () use ($admin) {
    expect(allows(InterestStatus::PENDING, InterestStatus::ACCEPTED, $admin))->toBeTrue();
    expect(allows(InterestStatus::PENDING, InterestStatus::REJECTED, $admin))->toBeTrue();
    expect(allows(InterestStatus::PENDING, InterestStatus::CANCELLED, $admin))->toBeTrue();
    expect(allows(InterestStatus::ACCEPTED, InterestStatus::CANCELLED, $admin))->toBeTrue();
});

it('resolves pending-document via the system only (FR-310 retry)', function () use ($system, $owner, $investor) {
    expect(allows(InterestStatus::ACCEPTED_PENDING_DOCUMENT, InterestStatus::ACCEPTED, $system))->toBeTrue();
    expect(allows(InterestStatus::ACCEPTED_PENDING_DOCUMENT, InterestStatus::ACCEPTED, $owner))->toBeFalse();
    expect(allows(InterestStatus::ACCEPTED_PENDING_DOCUMENT, InterestStatus::ACCEPTED, $investor))->toBeFalse();
});

it('forbids any transition into rejected/cancelled from a non-matching actor', function () use ($owner, $investor) {
    // المستثمر لا يستطيع القبول، صاحب الفكرة لا يستطيع الإلغاء.
    expect(allows(InterestStatus::ACCEPTED, InterestStatus::CANCELLED, $owner))->toBeFalse();
    expect(allows(InterestStatus::ACCEPTED_PENDING_DOCUMENT, InterestStatus::CANCELLED, $owner))->toBeFalse();
});
