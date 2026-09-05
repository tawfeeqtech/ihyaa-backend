<?php

namespace Database\Factories;

use App\Enums\ProjectState;
use App\Enums\ProjectStatus;
use App\Enums\VisibilityLevel;
use App\Models\Category;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->ideaOwner(),
            'category_id' => Category::factory(),
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(6),
            'status' => ProjectState::NEEDS_FUNDING,
            'publication_status' => ProjectStatus::PUBLISHED,
            'tags' => fake()->randomElements(['laravel', 'react', 'ai', 'fintech', 'saas', 'mobile'], 3),
            'github_url' => fake()->optional()->url(),
            'video_url' => null,
            'video_provider' => null,
            'budget_min' => fake()->optional()->numberBetween(10000, 100000),
            'budget_max' => null,
            'visibility_level' => VisibilityLevel::AFTER_AGREEMENT,
            'ai_score' => fake()->optional()->randomFloat(2, 40, 95),
            'view_count' => fake()->numberBetween(0, 500),
        ];
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'publication_status' => ProjectStatus::PUBLISHED,
        ]);
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'publication_status' => ProjectStatus::DRAFT,
        ]);
    }

    public function trashed(): static
    {
        return $this->state(fn (array $attributes) => [
            'deleted_at' => now(),
        ]);
    }
}
