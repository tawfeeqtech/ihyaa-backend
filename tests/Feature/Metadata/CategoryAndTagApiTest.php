<?php

namespace Tests\Feature\Metadata;

use App\Models\Category;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => 'null']);

    $this->owner = User::factory()->ideaOwner()->create();
    $this->admin = User::factory()->admin()->create();
});

it('allows an idea owner to create an independent tag before any project exists', function () {
    Sanctum::actingAs($this->owner);

    $response = $this->postJson('/api/tags', ['name' => ' Laravel '])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Laravel');

    expect(Tag::where('name', 'Laravel')->exists())->toBeTrue();
});

it('allows only an admin to edit or delete categories and project tags', function () {
    $category = Category::factory()->create();
    $tag = Tag::create(['name' => 'Laravel', 'slug' => 'laravel']);

    Sanctum::actingAs($this->owner);
    $this->putJson("/api/categories/{$category->id}", ['name_en' => 'Updated'])
        ->assertForbidden();
    $this->deleteJson("/api/categories/{$category->id}")->assertForbidden();
    $this->putJson("/api/tags/{$tag->id}", ['name' => 'PHP'])
        ->assertForbidden();
    $this->deleteJson("/api/tags/{$tag->id}")
        ->assertForbidden();

    Sanctum::actingAs($this->admin);
    $this->putJson("/api/categories/{$category->id}", ['name_en' => 'Updated'])
        ->assertOk();
    $this->putJson("/api/tags/{$tag->id}", ['name' => 'PHP'])
        ->assertOk()
        ->assertJsonPath('data.name', 'PHP');
    $this->deleteJson("/api/tags/{$tag->id}")
        ->assertOk();
    $this->deleteJson("/api/categories/{$category->id}")
        ->assertOk();
});

it('forbids an investor from managing categories or project tags', function () {
    $investor = User::factory()->investor()->create();
    Sanctum::actingAs($investor);

    $this->postJson('/api/categories', [
        'name_ar' => 'تصنيف',
        'name_en' => 'Category',
    ])->assertForbidden();

    $this->postJson('/api/tags', ['name' => 'PHP'])
        ->assertForbidden();
});
