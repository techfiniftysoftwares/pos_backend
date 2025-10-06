<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Business;
use Illuminate\Database\Eloquent\Factories\Factory;

class CategoryFactory extends Factory
{
    protected $model = Category::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'parent_id' => null,
            'name' => $this->faker->unique()->word(),
            'description' => $this->faker->sentence(),
            'is_active' => true,
        ];
    }

    public function inactive()
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function withParent(Category $parent)
    {
        return $this->state(fn (array $attributes) => [
            'parent_id' => $parent->id,
            'business_id' => $parent->business_id,
        ]);
    }
}
