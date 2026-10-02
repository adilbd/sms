<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use App\Models\Certificate;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Certificate>
 */
class CertificateFactory extends Factory
{
    public function definition(): array
    {
        static $sequence = 0;
        $sequence++;

        return [
            'type' => Certificate::TYPE_CHARACTER,
            'serial_no' => 'CHR-2026-9'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
            'student_id' => Student::factory(),
            'enrolment_id' => null,
            'academic_year_id' => AcademicYear::factory(),
            'issued_on' => '2026-10-15',
            'issued_by' => null,
            'data' => ['conduct' => 'ভালো'],
        ];
    }

    public function cancelled(string $reason = 'Issued by mistake'): static
    {
        return $this->state(fn () => ['cancelled_at' => now(), 'cancel_reason' => $reason]);
    }
}
