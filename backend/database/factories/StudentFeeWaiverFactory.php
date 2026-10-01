<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use App\Models\FeeHead;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A 50% waiver by default; pass `percent => null, fixed_amount => '100.00'` for a fixed one.
 *
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\StudentFeeWaiver>
 */
class StudentFeeWaiverFactory extends Factory
{
    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'academic_year_id' => AcademicYear::factory(),
            'fee_head_id' => FeeHead::factory(),
            'percent' => '50.00',
            'fixed_amount' => null,
            'reason' => null,
            'approved_by' => User::factory(),
        ];
    }
}
