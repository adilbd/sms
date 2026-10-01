<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AcademicYear>
 */
class AcademicYearFactory extends Factory
{
    /** The last year handed out; starts above anything a test hardcodes (2020-2099). */
    private static int $lastYear = 2099;

    /**
     * A year nobody else holds, with the name, code and Jan 1 to Dec 31 dates following it
     * (also when a test overrides `year`). Years count up from 2100 and skip any that
     * already exist, so the factory can't collide with a year a test creates by hand.
     */
    public function definition(): array
    {
        return [
            'year' => fn () => $this->nextYear(),
            'name' => fn (array $attributes) => (string) $attributes['year'],
            'code' => fn (array $attributes) => (string) $attributes['year'],
            'start_date' => fn (array $attributes) => "{$attributes['year']}-01-01",
            'end_date' => fn (array $attributes) => "{$attributes['year']}-12-31",
            'is_active' => false,
            'description' => null,
        ];
    }

    private function nextYear(): int
    {
        do {
            $year = ++self::$lastYear;
        } while (AcademicYear::query()->where('year', $year)->exists());

        return $year;
    }

    public function active(): static
    {
        return $this->state(['is_active' => true]);
    }
}
