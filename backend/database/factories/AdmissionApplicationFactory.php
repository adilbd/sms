<?php

namespace Database\Factories;

use App\Models\AdmissionApplication;
use App\Models\AdmissionRound;
use App\Models\Classes;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Builds only the row: the file paths are placeholders (tests that need a real file put
 * one on the private disk). A test supplies the round and class it cares about.
 *
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AdmissionApplication>
 */
class AdmissionApplicationFactory extends Factory
{
    private static int $sequence = 0;

    public function definition(): array
    {
        $n = ++self::$sequence;

        return [
            'application_no' => sprintf('ADM-2026-%06d', 900000 + $n),
            'round_id' => AdmissionRound::factory(),
            'class_id' => Classes::factory(),
            'group' => null,
            'shift_id' => null,
            'name_en' => fake()->name(),
            'name_bn' => null,
            'date_of_birth' => '2014-05-20',
            'gender' => 'male',
            'religion' => 'islam',
            'birth_registration_number' => str_pad((string) (20140000000000000 + $n), 17, '0', STR_PAD_LEFT),
            'nationality' => 'Bangladeshi',
            'father_name_en' => fake()->name('male'),
            'guardian_relation' => 'father',
            'guardian_name' => fake()->name('male'),
            'guardian_mobile' => '0171'.fake()->unique()->numerify('#######'),
            'present_address' => 'Dhaka',
            'photo_path' => 'admissions/'.fake()->uuid().'.jpg',
            'status' => AdmissionApplication::STATUS_SUBMITTED,
        ];
    }

    public function status(string $status): static
    {
        return $this->state(['status' => $status]);
    }
}
