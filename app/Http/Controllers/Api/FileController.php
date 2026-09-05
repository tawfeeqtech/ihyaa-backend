<?php

namespace App\Http\Controllers\Api;

use App\Enums\FileType;
use App\Http\Requests\UploadProjectFilesRequest;
use App\Models\Project;
use App\Models\ProjectFile;
use App\Services\Project\FileValidationService;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * ملفات المشروع — SRS-API-18 · SRS-F02-02.
 * الحدود: 5 صور × 5MB (jpeg/png/webp) + 3 PDF × 10MB — مركزية في config/uploads.php.
 * التخزين: Local Disk فقط (FILESYSTEM_DISK=public) — أسماء عشوائية (حماية المسار SRS-NFR-08).
 *
 * T162/T163: التفويض عبر ProjectPolicy::files · قواعد Laravel في UploadProjectFilesRequest.
 * T167: شكل الرفع images[] + pdfs[] منفصلان (القرار موثَّق في العقد).
 */
class FileController
{
    use ApiResponse;

    public function __construct(private readonly FileValidationService $fileValidation)
    {
    }

    /** L5 — رفع الملفات (RL-IO-07 · 10/دقيقة) */
    public function upload(UploadProjectFilesRequest $request, Project $project): JsonResponse
    {
        $data = $request->validated();

        if (empty($data['images']) && empty($data['pdfs'])) {
            return $this->unprocessable('NO_FILES', __('files.none_provided'));
        }

        // قيود العدد لكل مشروع (ليس فقط لكل طلب)
        $existingImages = $project->files()->where('type', FileType::IMAGE)->count();
        $existingPdfs = $project->files()->where('type', FileType::PDF)->count();

        $newImages = count($data['images'] ?? []);
        $newPdfs = count($data['pdfs'] ?? []);

        if ($existingImages + $newImages > (int) config('uploads.images.count')) {
            return $this->unprocessable('IMAGE_LIMIT_EXCEEDED', __('files.image_limit', ['max' => config('uploads.images.count')]));
        }

        if ($existingPdfs + $newPdfs > (int) config('uploads.pdfs.count')) {
            return $this->unprocessable('PDF_LIMIT_EXCEEDED', __('files.pdf_limit', ['max' => config('uploads.pdfs.count')]));
        }

        // الطبقة الثانية (T135): تحقق صريح من كل ملف — MIME حقيقي + امتداد + توقيعات تنفيذية
        foreach ($data['images'] ?? [] as $file) {
            try {
                $this->fileValidation->validateFile($file, FileType::IMAGE);
            } catch (ValidationException $e) {
                return $this->unprocessable('FILE_INVALID', $e->getMessage(), $e->errors());
            }
        }

        foreach ($data['pdfs'] ?? [] as $file) {
            try {
                $this->fileValidation->validateFile($file, FileType::PDF);
            } catch (ValidationException $e) {
                return $this->unprocessable('FILE_INVALID', $e->getMessage(), $e->errors());
            }
        }

        $hasCover = $project->files()->where('type', FileType::IMAGE)->where('is_cover', true)->exists();
        $maxSort = (int) $project->files()->max('sort_order');

        $created = [];

        // الصور — أول صورة تُرفع تصبح الغلاف افتراضياً (SRS-F02-02)
        foreach ($data['images'] ?? [] as $file) {
            $path = $file->store('projects/'.$project->id, 'public');

            $isCover = ! $hasCover;

            $created[] = $project->files()->create([
                'type' => FileType::IMAGE,
                'file_path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
                'is_cover' => $isCover,
                'sort_order' => ++$maxSort,
            ]);

            $hasCover = $hasCover || $isCover;
        }

        // PDFs
        foreach ($data['pdfs'] ?? [] as $file) {
            $path = $file->store('projects/'.$project->id, 'public');

            $created[] = $project->files()->create([
                'type' => FileType::PDF,
                'file_path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
                'is_cover' => false,
                'sort_order' => ++$maxSort,
            ]);
        }

        return $this->created(
            collect($created)->map->toArrayApi(),
            __('files.uploaded')
        );
    }

    /** حذف ملف — امتداد مقترح (خارج الـ 49) ضروري لتجربة رفع كاملة */
    public function destroy(Request $request, Project $project, ProjectFile $file): JsonResponse
    {
        if ($request->user()->cannot('files', $project)) {
            return $this->forbidden();
        }

        if ((int) $file->project_id !== (int) $project->id) {
            return $this->notFound();
        }

        Storage::disk('public')->delete($file->file_path);
        $file->delete();

        return $this->noContent(__('files.deleted'));
    }
}
