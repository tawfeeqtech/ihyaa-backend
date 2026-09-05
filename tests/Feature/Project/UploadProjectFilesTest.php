<?php

namespace Tests\Feature\Project;

use App\Enums\FileType;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => 'null']);

    $this->owner = User::factory()->ideaOwner()->create();
    $this->project = Project::factory()->published()->create(['user_id' => $this->owner->id]);

    Storage::fake('public');
    Sanctum::actingAs($this->owner);
});

// ——————————————————————— US-010: رفع ملفات المشروع (SRS-API-18 · SRS-F02-02) ———————————————————————

it('uploads up to 5 images and sets the first as cover', function () {
    $images = collect(range(1, 5))
        ->map(fn (int $i) => UploadedFile::fake()->image("photo{$i}.png", 100, 100))
        ->all();

    $this->postJson("/api/projects/{$this->project->id}/files", ['images' => $images])
        ->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonCount(5, 'data');

    $this->assertDatabaseCount('project_files', 5);
    $this->assertDatabaseHas('project_files', [
        'project_id' => $this->project->id,
        'type' => FileType::IMAGE->value,
        'is_cover' => true,
    ]);

    expect($this->project->files()->where('type', FileType::IMAGE)->where('is_cover', true)->count())->toBe(1);
});

it('rejects a 6th image in a single request', function () {
    $images = collect(range(1, 6))
        ->map(fn (int $i) => UploadedFile::fake()->image("photo{$i}.png", 100, 100))
        ->all();

    $this->postJson("/api/projects/{$this->project->id}/files", ['images' => $images])
        ->assertStatus(422)
        ->assertJsonValidationErrors('images');
});

it('rejects new images that would exceed the project limit', function () {
    foreach (range(0, 3) as $i) {
        $this->project->files()->create([
            'type' => FileType::IMAGE,
            'file_path' => "projects/{$this->project->id}/existing{$i}.jpg",
            'original_name' => "existing{$i}.jpg",
            'mime_type' => 'image/jpeg',
            'file_size' => 1000,
            'is_cover' => $i === 0,
            'sort_order' => $i,
        ]);
    }

    $images = collect(range(1, 2))
        ->map(fn (int $i) => UploadedFile::fake()->image("new{$i}.png", 100, 100))
        ->all();

    $this->postJson("/api/projects/{$this->project->id}/files", ['images' => $images])
        ->assertStatus(422)
        ->assertJsonPath('code', 'IMAGE_LIMIT_EXCEEDED');
});

it('rejects an image larger than 5MB', function () {
    $this->postJson("/api/projects/{$this->project->id}/files", [
        'images' => [UploadedFile::fake()->image('big.png', 100, 100)->size(6000)],
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('images.0');
});

it('rejects a php-named file at the Laravel validation layer', function () {
    // Laravel 13 يمنع امتدادات php-family تلقائياً في validateMimes (shouldBlockPhpUpload).
    $this->postJson("/api/projects/{$this->project->id}/files", [
        'images' => [UploadedFile::fake()->image('evil.php', 100, 100)],
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_FAILED');
});

it('rejects an executable signature inside an image through deep validation', function () {
    // اسم الصورة سليم (png) فيجتاز قواعد Laravel، لكن المحتوى يبدأ بتوقيع PHP
    // فترفضه الطبقة الثانية (T135) بكود FILE_INVALID.
    $this->postJson("/api/projects/{$this->project->id}/files", [
        'images' => [UploadedFile::fake()->createWithContent('photo.png', '<?php echo "pwned";')],
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'FILE_INVALID');
});

it('uploads PDF files alongside images', function () {
    $pdf = UploadedFile::fake()->createWithContent(
        'proposal.pdf',
        "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
        ."2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\n"
        ."trailer\n<< /Root 1 0 R >>\nendobj\n%%EOF"
    );

    $this->postJson("/api/projects/{$this->project->id}/files", [
        'images' => [UploadedFile::fake()->image('photo.png', 100, 100)],
        'pdfs' => [$pdf],
    ])
        ->assertStatus(201)
        ->assertJsonCount(2, 'data');

    $this->assertDatabaseHas('project_files', [
        'project_id' => $this->project->id,
        'type' => FileType::PDF->value,
        'original_name' => 'proposal.pdf',
    ]);
});

it('forbids a non-owner from uploading files', function () {
    $other = User::factory()->ideaOwner()->create();
    Sanctum::actingAs($other);

    $this->postJson("/api/projects/{$this->project->id}/files", [
        'images' => [UploadedFile::fake()->image('photo.png', 100, 100)],
    ])
        ->assertStatus(403)
        ->assertJsonPath('code', 'FORBIDDEN');
});
