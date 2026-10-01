<?php

namespace Database\Factories;

use App\Models\FeeDue;
use App\Models\FeeHead;
use App\Models\StudentEnrolment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An unpaid ৳800 due. The student follows the enrolment, whichever enrolment a test passes.
 *
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\FeeDue>
 */
class FeeDueFactory extends Factory
{
    public function definition(): array
    {
        return [
            'enrolment_id' => StudentEnrolment::factory(),
            'student_id' => fn (array $attributes) => StudentEnrolment::findOrFail($attributes['enrolment_id'])->student_id,
            'fee_head_id' => FeeHead::factory(),
            'period' => '2026-10',
            'amount' => '800.00',
            'waiver_amount' => '0.00',
            'net_amount' => '800.00',
            'paid_amount' => '0.00',
            'status' => FeeDue::STATUS_UNPAID,
            'due_date' => '2026-10-10',
        ];
    }
}
