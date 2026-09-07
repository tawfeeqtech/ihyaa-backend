<?php

namespace App\Services\Project;

use App\Enums\FileType;
use App\Models\Project;
use App\Models\ProjectFile;
use App\Models\User;
use Illuminate\Http\UploadedFile;

/**
 * T160 — منطق أعمال المشاريع (SRS-API-15..17 · SRS-F02).
 *
 * يتولّى الإنشاء والتحديث + استنتاج مزوّد الفيديو (T133) + كشف التغييرات
 * الجوهرية التي تستدعي اقتراح إعادة تقييم يدوية (SRS-F04-02) + رفع صورة الغلاف.
 * (التحقق من الصحة في StoreProjectRequest/UpdateProjectRequest — T163.)
 */
class ProjectService
{
    /**
     * الحقول الجوهرية — تغيّرها يستدعي اقتراح إعادة تقييم (contract §PUT / SRS-F04-02).
     * ملاحظة: العقد يضيف video_url أيضاً — يُفصَّل في T168/T166.
     */
    public const SIGNIFICANT_FIELDS = ['description', 'bio', 'tags', 'github_url', 'status'];

    public function __construct(private readonly ?FileValidationService $fileValidation = null)
    {
    }

    /** إنشاء مشروع جديد لصاحب الفكرة (SRS-API-15). */
    public function create(User $user, array $data): Project
    {
        $coverImage = $data['cover_image'] ?? null;
        unset($data['cover_image']);

        $project = $user->projects()->create($this->applyVideoProviderInference($data));

        if ($coverImage instanceof UploadedFile) {
            $this->saveCoverImage($project, $coverImage);
        }

        return $project;
    }

    /**
     * تحديث المشروع (SRS-API-16).
     *
     * @return array{project: Project, significant_changes: bool, changed_significant_fields: list<string>}
     *         significant_changes: تغيّرت الحقول الجوهرية → اقتراح إعادة تقييم يدوية
     *         (لا تلقائية إطلاقاً — SRS-F04-02).
     *         changed_significant_fields: أسماء الحقول الجوهرية التي تغيّرت فعلاً
     *         (لحدث ProjectContentChanged — T079).
     */
    public function update(Project $project, array $data): array
    {
        $coverImage = $data['cover_image'] ?? null;
        unset($data['cover_image']);

        $original = $project->only(self::SIGNIFICANT_FIELDS);

        $project->update($this->applyVideoProviderInference($data));

        if ($coverImage instanceof UploadedFile) {
            $this->saveCoverImage($project, $coverImage);
        }

        $changedFields = collect(self::SIGNIFICANT_FIELDS)
            ->filter(fn (string $key) => json_encode($original[$key] ?? null) !== json_encode($project->{$key}))
            ->values()
            ->all();

        return [
            'project' => $project,
            'significant_changes' => $changedFields !== [],
            'changed_significant_fields' => $changedFields,
        ];
    }

    /**
     * حفظ صورة غلاف المشروع في جدول project_files والقرص العام.
     */
    public function saveCoverImage(Project $project, UploadedFile $file): ProjectFile
    {
        $validator = $this->fileValidation ?? app(FileValidationService::class);
        $validator->validateFile($file, FileType::IMAGE);

        // إلغاء وسم الغلاف عن أي صورة غلاف سابقة
        $project->files()->where('type', FileType::IMAGE)->where('is_cover', true)->update(['is_cover' => false]);

        $maxSort = (int) $project->files()->max('sort_order');
        $path = $file->store('projects/'.$project->id, 'public');

        return $project->files()->create([
            'type' => FileType::IMAGE,
            'file_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'is_cover' => true,
            'sort_order' => ++$maxSort,
        ]);
    }

    /**
     * T133 — استنتاج مزوّد الفيديو من رابط YouTube/Vimeo عند الحفظ إن غاب.
     */
    public function inferVideoProvider(?string $videoUrl): ?string
    {
        if (! $videoUrl) {
            return null;
        }

        if (preg_match('/(?:youtube\.com|youtu\.be)/i', $videoUrl)) {
            return 'youtube';
        }

        if (str_contains(strtolower($videoUrl), 'vimeo.com')) {
            return 'vimeo';
        }

        return null;
    }

    /**
     * تطبيق استنتاج video_provider على البيانات المُدخَلة.
     * يُستنتج فقط عند وجود video_url في الطلب؛ وإن غاب الحقلان معاً
     * (تحديث جزئي) لا يُلمس video_provider المخزّن.
     */
    protected function applyVideoProviderInference(array $data): array
    {
        if (array_key_exists('video_url', $data)) {
            $data['video_provider'] = $data['video_provider']
                ?? $this->inferVideoProvider($data['video_url']);
        }

        return $data;
    }
}
