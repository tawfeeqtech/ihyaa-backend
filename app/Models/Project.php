<?php

namespace App\Models;

use App\Enums\EvaluationStatus;
use App\Enums\FileType;
use App\Enums\InterestStatus;
use App\Enums\ProjectState;
use App\Enums\ProjectStatus;
use App\Enums\VideoProvider;
use App\Enums\VisibilityLevel;
use App\Http\Resources\ProjectResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;

class Project extends Model
{
    use HasFactory, Searchable, SoftDeletes;

    public const TRASH_RECOVERY_DAYS = 30;          // سلة مهملات 30 يوماً (SRS-F02-06)

    public const RE_EVALUATION_CACHE_HOURS = 24;    // كاش إعادة التقييم (SRS-AI-C01)

    public const MAX_TAGS = 10;

    public const DEFAULT_PAGE_SIZE = 12;            // SRS-F07-05

    public const MAX_PAGE_SIZE = 12;                // SRS-F07-05 · US-040 س4: clamp 1–12 (T156)

    /**
     * الحقول القابلة للتعبئة — توثيق T168 (حقول خارج النطاق الأساسي لـ Sprint 1):
     *
     * هذه الحقول توسعة مقبولة لميزات Sprint 2-4 (لا تُحذف) — مصدر كل حقل:
     *  - publication_status  : حالة النشر/العرض draft|published|archived — منفصلة عن status التجارية.
     *                          المصدر: create_projects (2026_08_02_000005) + docs/api/enums.md §1.2.
     *                          الاستهلاك الكامل (نشر/أرشفة من لوحة صاحب الفكرة) في Sprint 4.
     *  - team                : أعضاء الفريق JSON [{name, role}] — اختياري (SRS-F02-01).
     *                          المصدر: add_team_to_projects (2026_08_18_000001).
     *                          يُستهلك من محرك تقييم AI (بُعد الفريق) ولوحة صاحب الفكرة — Sprint 2+.
     *  - visibility_level    : مستوى الإفصاح عن تقرير AI (1 زائر | 2 مسجّل | 3 بعد الاتفاق).
     *                          المصدر: create_projects + docs/api/enums.md §1.4 + T127 (default 2).
     *                          يُستهلك في reportAccessFor/effectiveVisibilityFor — Sprint 2-4.
     *  - last_evaluation_at  : كاش الـ 24 ساعة لإعادة التقييم (SRS-AI-C01) — آخر تقييم مكتمل.
     *                          المصدر: create_projects + add_ai_fields (2026_08_17_000007 فهرس).
     *                          يُحدّث من محرك التقييم — Sprint 2.
     *
     * ملاحظة جدول role_user (pivot users↦roles): توسعة مقبولة من Sprint 1 — عمود `role` على
     * جدول users هو المصدر الأساسي لدور المستخدم، والـ pivot مرجعي يُزامن عبر User::setRole
     * (SRS-F01-07 — أول دخول OAuth).
     */
    protected $fillable = [
        'user_id',
        'category_id',
        'title',
        'description',
        'status',
        'publication_status',      // حالة النشر draft|published|archived — enums.md §1.2
        'tags',
        'team',                    // أعضاء الفريق JSON [{name, role}] — SRS-F02-01
        'github_url',
        'video_url',
        'video_provider',
        'budget_min',
        'budget_max',
        'visibility_level',        // مستوى الإفصاح عن تقرير AI (1|2|3) — enums.md §1.4
        'ai_score',
        'view_count',
        'last_evaluation_at',      // كاش إعادة التقييم (SRS-AI-C01)
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProjectState::class,
            'publication_status' => ProjectStatus::class,
            'visibility_level' => VisibilityLevel::class,
            'video_provider' => VideoProvider::class,
            'tags' => 'array',
            'team' => 'array',
            'budget_min' => 'float',
            'budget_max' => 'float',
            'ai_score' => 'float',
            'view_count' => 'integer',
            'last_evaluation_at' => 'datetime',
        ];
    }

    // ——————————————————————— العلاقات ———————————————————————

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(ProjectFile::class)->orderBy('sort_order');
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(Evaluation::class)->orderByDesc('version');
    }

    // ——————————————————————— فهرسة البحث (Scout/Meilisearch — plan §5.1 · data-model §8.1) ———————————————————————

    /** اسم فهرس Meilisearch — plan §5.1 (T007) */
    public function searchableAs(): string
    {
        return 'projects_index';
    }

    /** سجل تقييمات AI على الجدول الجديد `evaluations` (Sprint 2 — App\Models\Evaluation) */
    public function evaluationHistory(): HasMany
    {
        return $this->hasMany(Evaluation::class);
    }

    /** آخر تقييم مكتمل — مصدر `overall_score` في الفهرس (plan §5.1) */
    public function latestCompletedEvaluation(): ?Evaluation
    {
        // حارس: نموذج Evaluation/جدوله يُنشآن في Sprint 2 (مهمة موازية) — قبل وجوده تُعتبر الدرجة null بلا كسر
        if (! class_exists(Evaluation::class)) {
            return null;
        }

        return $this->evaluationHistory()
            ->where('status', EvaluationStatus::COMPLETED)
            ->latest('created_at')
            ->first();
    }

    /** وثيقة الفهرس — id/title/description/category/tags/status/overall_score/has_score/views_count/created_at/user_id (plan §5.1) */
    public function toSearchableArray(): array
    {
        $latest = $this->latestCompletedEvaluation();

        return [
            'id'            => (string) $this->id,
            'title'         => $this->title,
            'description'   => $this->description,
            'category'      => $this->category?->slug,
            'tags'          => $this->tags ?? [],
            'status'        => $this->status?->value,
            'overall_score' => $latest?->overall_score,
            'has_score'     => $latest !== null,
            'views_count'   => $this->view_count,
            'created_at'    => $this->created_at?->timestamp,
            'user_id'       => (string) $this->user_id,
        ];
    }

    public function interests(): HasMany
    {
        return $this->hasMany(Interest::class);
    }

    public function savedBy(): HasMany
    {
        return $this->hasMany(SavedProject::class);
    }

    // ——————————————————————— Scopes ———————————————————————

    /** المشاريع المنشورة فقط (المعرض والبحث) */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('publication_status', ProjectStatus::PUBLISHED);
    }

    /** فلترة بالحالة التجارية (completed / needs_development / needs_funding) */
    public function scopeOfState(Builder $query, ?string $state): Builder
    {
        return $state ? $query->where('status', $state) : $query;
    }

    public function scopeOfCategory(Builder $query, ?string $slug): Builder
    {
        return $slug
            ? $query->whereHas('category', fn (Builder $q) => $q->where('slug', $slug))
            : $query;
    }

    /** فلترة بدرجة التقييم */
    public function scopeScoreBetween(Builder $query, ?float $min, ?float $max): Builder
    {
        if ($min !== null) {
            $query->where('ai_score', '>=', $min);
        }
        if ($max !== null) {
            $query->where('ai_score', '<=', $max);
        }

        return $query;
    }

    /** بحث نصي بسيط (يُستبدل بـ Meilisearch/Scout في مرحلة لاحقة) */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        return $term
            ? $query->where(function (Builder $q) use ($term) {
                $q->where('title', 'like', "%{$term}%")
                    ->orWhere('description', 'like', "%{$term}%")
                    ->orWhere('tags', 'like', "%{$term}%");
            })
            : $query;
    }

    /** الفرز: ai_score | created_at | view_count (SRS-F06-04) */
    public function scopeSortBy(Builder $query, ?string $sort = 'created_at', ?string $direction = 'desc'): Builder
    {
        $column = match ($sort) {
            'ai_score', 'view_count' => $sort,
            default => 'created_at',
        };

        return $query->orderBy($column, strtolower($direction) === 'asc' ? 'asc' : 'desc');
    }

    /** مشاريع المالك المحذوفة (سلة المهملات) — ضمن 30 يوماً */
    public function scopeTrash(Builder $query): Builder
    {
        return $query->onlyTrashed()
            ->where('deleted_at', '>=', now()->subDays(self::TRASH_RECOVERY_DAYS));
    }

    // ——————————————————————— الإفصاح (SRS-F05-05) ———————————————————————

    public function isOwner(?User $user): bool
    {
        return $user !== null && (int) $user->id === (int) $this->user_id;
    }

    /** هل لدى المستثمر طلب اهتمام مقبول؟ */
    public function hasAcceptedInterestFrom(User $user): bool
    {
        return $this->interests()
            ->where('investor_id', $user->id)
            ->where('status', InterestStatus::ACCEPTED)
            ->exists();
    }

    /**
     * مستوى الوصول الفعلي للتقرير — docs/api/enums.md §1.4 · US-038 AC2:
     * none | overall | dimensions | full
     *
     * مصفوفة الإفصاح (T127):
     *  - زائر غير مسجّل: overall فقط إن visibility_level = 1، وإلا none
     *  - مسجّل (غير مالك): dimensions + radar دائماً — 1 → overall، 2/3 → dimensions
     *  - مالك أو مستثمر بعد اتفاق مقبول: full
     */
    public function reportAccessFor(?User $user): string
    {
        if ($this->isOwner($user) || ($user && $this->hasAcceptedInterestFrom($user))) {
            return 'full';
        }

        if ($user) {
            return match ($this->visibility_level) {
                VisibilityLevel::VISITOR => 'overall',
                VisibilityLevel::REGISTERED, VisibilityLevel::AFTER_AGREEMENT => 'dimensions',
            };
        }

        return $this->visibility_level === VisibilityLevel::VISITOR ? 'overall' : 'none';
    }

    /** المستوى الفعلي المطبَّق (يُرجع في كل استجابة — SRS §1.4) */
    public function effectiveVisibilityFor(?User $user): int
    {
        if ($this->isOwner($user) || ($user && $this->hasAcceptedInterestFrom($user))) {
            return VisibilityLevel::AFTER_AGREEMENT->value;
        }

        if ($user) {
            return min(VisibilityLevel::REGISTERED->value, $this->visibility_level->value);
        }

        return $this->visibility_level === VisibilityLevel::VISITOR
            ? VisibilityLevel::VISITOR->value
            : 0;
    }

    /** آخر تقييم مكتمل (أو جزئي) — للعرض والفرز */
    public function latestEvaluation(): ?Evaluation
    {
        return $this->evaluations()
            ->whereIn('status', [EvaluationStatus::COMPLETED, EvaluationStatus::PARTIAL])
            ->first();
    }

    public function coverUrl(): ?string
    {
        // T157: أعد استخدام علاقة files المحمّلة مسبقاً (مع fallback لاستعلام جديد إن لم تُحمّل)
        // — يمنع N+1 في قوائم المعرض (index يحمّل files مسبقاً).
        $files = $this->relationLoaded('files') ? $this->files : $this->files()->get();

        $cover = $files->where('type', FileType::IMAGE)->where('is_cover', true)->first()
            ?? $files->where('type', FileType::IMAGE)->first();

        return $cover ? asset('storage/'.$cover->file_path) : null;
    }

    /** بطاقة المعرض (SRS-F07) — T161: التفويض إلى ProjectResource::card() */
    public function toCardArray(?User $viewer = null): array
    {
        return ProjectResource::make($this)->card($viewer);
    }
}
