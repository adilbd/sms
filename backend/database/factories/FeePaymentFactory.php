<?php

namespace Database\Factories;

use App\Models\FeePayment;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A bare cash payment row (no allocations). Tests that need the dues updated collect
 * through the API or FeePaymentService instead.
 *
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\FeePayment>
 */
class FeePaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'receipt_no' => '2026-'.str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'student_id' => Student::factory(),
            'paid_at' => '2026-10-01 04:00:00',
            'method' => FeePayment::METHOD_CASH,
            'transaction_id' => null,
            'amount' => '800.00',
            'collected_by' => User::factory(),
            'note' => null,
        ];
    }
}
