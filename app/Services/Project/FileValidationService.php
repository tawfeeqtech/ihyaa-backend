<?php

namespace App\Services\Project;

use App\Enums\FileType;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * T135 — تحقق صريح من ملفات الرفع (طبقة عمق بعد قواعد Laravel).
 *
 * الترتيب: عدد → نوع → حجم → امتداد → MIME حقيقي (finfo) → منع التوقيعات التنفيذية.
 * الحدود والامتدادات مركزية في config/uploads.php — لا تُكرَّر هنا.
 */
class FileValidationService
{
    /** امتدادات تنفيذية ممنوعة — حماية إضافية مهما بدا الاسم آمناً (exe/php/sh/bat/jar/msi/elf...) */
    public const EXECUTABLE_EXTENSIONS = [
        'exe', 'php', 'phtml', 'php3', 'php4', 'php5', 'phar', 'sh', 'bash',
        'bat', 'cmd', 'com', 'jar', 'msi', 'elf', 'dll', 'so', 'py', 'pl',
        'cgi', 'asp', 'aspx', 'jsp', 'js', 'mjs', 'vbs', 'vbe', 'ps1', 'psm1',
        'app', 'bin', 'scr', 'reg', 'wsf', 'hta',
    ];

    /** توقيعات تنفيذية تُفحص على المحتوى الخام — دفاع متعمق حتى لو خُدع فحص MIME */
    public const EXECUTABLE_SIGNATURES = [
        "\x7fELF",          // Linux ELF (application/x-elf)
        'MZ',               // Windows PE — exe/dll/msi
        '<?php',            // PHP
        '<?=',              // PHP short-echo
        '#!/',              // shebang scripts (sh/bash/python/perl...)
        "PK\x03\x04",       // ZIP container (jar/msi...)
    ];

    /**
     * تحقق من طلب رفع كامل (قيود العدد + كل ملف).
     *
     * @param  UploadedFile[]  $images
     * @param  UploadedFile[]  $pdfs
     * @throws ValidationException
     */
    public function validateUpload(array $images, array $pdfs, int $existingImages = 0, int $existingPdfs = 0): void
    {
        $imageLimit = (int) config('uploads.images.count');
        $pdfLimit = (int) config('uploads.pdfs.count');

        if (count($images) + $existingImages > $imageLimit) {
            throw ValidationException::withMessages([
                'images' => __('files.image_limit', ['max' => $imageLimit]),
            ]);
        }

        if (count($pdfs) + $existingPdfs > $pdfLimit) {
            throw ValidationException::withMessages([
                'pdfs' => __('files.pdf_limit', ['max' => $pdfLimit]),
            ]);
        }

        foreach ($images as $file) {
            $this->validateFile($file, FileType::IMAGE);
        }

        foreach ($pdfs as $file) {
            $this->validateFile($file, FileType::PDF);
        }
    }

    /**
     * تحقق من ملف واحد حسب نوعه: حجم → امتداد → توقيع تنفيذي → MIME حقيقي.
     *
     * @throws ValidationException
     */
    public function validateFile(UploadedFile $file, FileType $type): void
    {
        $this->assertReadable($file, $type);
        $this->assertSize($file, $type);
        $this->assertExtension($file, $type);
        $this->assertContent($file, $type);
    }

    /**
     * كشف التوقيعات التنفيذية على محتوى خام — مكشوفة للاختبار المباشر.
     */
    public function hasExecutableSignature(string $content): bool
    {
        foreach (self::EXECUTABLE_SIGNATURES as $signature) {
            if (str_starts_with($content, $signature)) {
                return true;
            }
        }

        return false;
    }

    protected function assertReadable(UploadedFile $file, FileType $type): void
    {
        $path = $file->getRealPath();

        if ($path === false || ! is_file($path) || filesize($path) === 0) {
            $this->fail($type, __('files.invalid'));
        }
    }

    protected function assertSize(UploadedFile $file, FileType $type): void
    {
        $maxKb = (int) config("uploads.{$this->configKey($type)}.max_kb");
        $size = $file->getSize();

        if ($size === false || $size > $maxKb * 1024) {
            $this->fail($type, __('files.too_large', ['max_kb' => $maxKb]));
        }
    }

    protected function assertExtension(UploadedFile $file, FileType $type): void
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());

        if ($extension === '' || ! in_array($extension, $this->allowedExtensions($type), true)) {
            $this->fail($type, __('files.invalid_extension'));
        }

        if (in_array($extension, self::EXECUTABLE_EXTENSIONS, true)) {
            $this->fail($type, __('files.executable_blocked'));
        }
    }

    protected function assertContent(UploadedFile $file, FileType $type): void
    {
        $content = $this->readHead($file);

        if ($content === false || $content === '') {
            $this->fail($type, __('files.invalid'));
        }

        // التوقيعات التنفيذية أولاً — تُرفض مهما كان ما سيكتشفه finfo
        if ($this->hasExecutableSignature($content)) {
            $this->fail($type, __('files.executable_blocked'));
        }

        $mime = $this->detectMime($content);

        if (! in_array($mime, $this->allowedMimes($type), true)) {
            $this->fail($type, __('files.mime_mismatch'));
        }
    }

    protected function readHead(UploadedFile $file): string|false
    {
        $path = $file->getRealPath();

        if ($path === false) {
            return false;
        }

        return file_get_contents($path, false, null, 0, 8192);
    }

    protected function detectMime(string $content): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);

        return $finfo->buffer($content) ?: '';
    }

    /** @return string[] */
    protected function allowedExtensions(FileType $type): array
    {
        return (array) config("uploads.{$this->configKey($type)}.mimes");
    }

    /** @return string[] */
    protected function allowedMimes(FileType $type): array
    {
        return match ($type) {
            FileType::IMAGE => ['image/jpeg', 'image/png', 'image/webp'],
            FileType::PDF => ['application/pdf'],
            default => [],
        };
    }

    protected function configKey(FileType $type): string
    {
        return $type === FileType::IMAGE ? 'images' : 'pdfs';
    }

    protected function fail(FileType $type, string $message): never
    {
        throw ValidationException::withMessages([$type->value => $message]);
    }
}
