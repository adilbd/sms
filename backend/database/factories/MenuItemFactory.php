<?php

namespace Database\Factories;

use App\Models\MenuItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\MenuItem>
 */
class MenuItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'location' => MenuItem::LOCATION_HEADER,
            'parent_id' => null,
            'label' => fake()->unique()->words(2, true),
            'type' => MenuItem::TYPE_ROUTE,
            'page_id' => null,
            'route_name' => 'home',
            'url' => null,
            'sort_order' => 0,
            'is_active' => true,
            'open_in_new_tab' => false,
        ];
    }

    public function heading(): static
    {
        return $this->state(fn () => [
            'type' => MenuItem::TYPE_HEADING,
            'route_name' => null,
        ]);
    }

    public function page(int $pageId): static
    {
        return $this->state(fn () => [
            'type' => MenuItem::TYPE_PAGE,
            'page_id' => $pageId,
            'route_name' => null,
        ]);
    }

    public function url(string $url = 'https://example.com'): static
    {
        return $this->state(fn () => [
            'type' => MenuItem::TYPE_URL,
            'url' => $url,
            'route_name' => null,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
