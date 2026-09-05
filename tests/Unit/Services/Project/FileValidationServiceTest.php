<?php

namespace Tests\Unit\Services\Project;

use App\Enums\FileType;
use App\Services\Project\FileValidationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

/** GIF 1×1 حقيقي — يُكتشف MIME كـ image/gif عبر finfo */
function gifBytes(): string
{
    return "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff"
        ."\x21\xf9\x04\x01\x00\x00\x00\x00\x2c\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x44\x01\x00\x3b";
}

/** PDF حد أدنى صالح — يبدأ بـ %PDF ليُكتشف MIME كـ application/pdf */
function pdfBytes(): string
{
    return "%PDF-1.4\n"
        ."1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
        ."2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\n"
        ."trailer\n<< /Root 1 0 R >>\nendobj\n"
        ."%%EOF";
}

// ——————————————————————— حالات القبول ———————————————————————

it('accepts a valid PNG image (T135)', function () {
    $service = new FileValidationService();

    $file = UploadedFile::fake()->image('photo.png');

    // لا يجب أن يرمي — لو رمى يفشل الاختبار تلقائياً
    $service->validateFile($file, FileType::IMAGE);

    expect(true)->toBeTrue();
});

it('accepts a valid PDF (T135)', function () {
    $service = new FileValidationService();

    $file = UploadedFile::fake()->createWithContent('doc.pdf', pdfBytes());

    $service->validateFile($file, FileType::PDF);

    expect(true)->toBeTrue();
});

// ——————————————————————— حالات الرفض ———————————————————————

it('rejects a spoofed extension (GIF renamed to .jpg) (T135)', function () {
    $service = new FileValidationService();

    $file = UploadedFile::fake()->createWithContent('photo.jpg', gifBytes());

    expect(fn () => $service->validateFile($file, FileType::IMAGE))
        ->toThrow(ValidationException::class);
});

it('rejects executable PHP content renamed to .png (T135)', function () {
    $service = new FileValidationService();

    $file = UploadedFile::fake()->createWithContent('evil.png', '<?php echo "pwned";');

    expect(fn () => $service->validateFile($file, FileType::IMAGE))
        ->toThrow(ValidationException::class);
});

it('rejects an ELF binary renamed to .jpg (T135)', function () {
    $service = new FileValidationService();

    $file = UploadedFile::fake()->createWithContent('evil.jpg', "\x7fELF".str_repeat("\x00", 16));

    expect(fn () => $service->validateFile($file, FileType::IMAGE))
        ->toThrow(ValidationException::class);
});

it('rejects files exceeding the size limit (T135)', function () {
    $service = new FileValidationService();

    // 10MB+1 → يتجاوز حد PDF البالغ 10240KB
    $file = UploadedFile::fake()->createWithContent('big.pdf', str_repeat('x', 10240 * 1024 + 1));

    expect(fn () => $service->validateFile($file, FileType::PDF))
        ->toThrow(ValidationException::class);
});

it('rejects executable extensions regardless of content (T135)', function () {
    $service = new FileValidationService();

    // امتداد .php غير مسموح في القائمة البيضاء أصلاً — رفض في خطوة الامتداد
    $file = UploadedFile::fake()->createWithContent('evil.php', 'anything');

    expect(fn () => $service->validateFile($file, FileType::IMAGE))
        ->toThrow(ValidationException::class);
});

// ——————————————————————— التوقيعات التنفيذية مباشرة ———————————————————————

it('detects executable signatures on raw content (T135)', function () {
    $service = new FileValidationService();

    expect($service->hasExecutableSignature("\x7fELF\x02\x01\x01"))->toBeTrue();
    expect($service->hasExecutableSignature("MZ\x90\x00\x03"))->toBeTrue();
    expect($service->hasExecutableSignature('<?php echo 1;'))->toBeTrue();
    expect($service->hasExecutableSignature('<?= $x ?>'))->toBeTrue();
    expect($service->hasExecutableSignature('#!/bin/sh'))->toBeTrue();
    expect($service->hasExecutableSignature("PK\x03\x04\x14\x00"))->toBeTrue();

    expect($service->hasExecutableSignature("\x89PNG\r\n\x1a\n\x00"))->toBeFalse();
    expect($service->hasExecutableSignature('%PDF-1.4'))->toBeFalse();
});

// ——————————————————————— قيود العدد (validateUpload) ———————————————————————

it('rejects exceeding the image count via validateUpload (T135)', function () {
    $service = new FileValidationService();

    $images = [];
    for ($i = 0; $i < 5; $i++) {
        $images[] = UploadedFile::fake()->image("img{$i}.png");
    }

    // 5 جديدة + 1 موجودة = 6 > الحد (5)
    expect(fn () => $service->validateUpload($images, [], 1, 0))
        ->toThrow(ValidationException::class);
});

it('accepts a batch within limits via validateUpload (T135)', function () {
    $service = new FileValidationService();

    $service->validateUpload([UploadedFile::fake()->image('a.png')], [], 0, 0);

    expect(true)->toBeTrue();
});
