<?php

namespace Tests\Concerns;

use App\Models\Classes;
use App\Models\FeeHead;
use App\Models\FeeRate;
use App\Models\Section;
use App\Models\StudentEnrolment;
use App\Models\StudentFeeWaiver;
use App\Models\User;

/**
 * The exam test school (active 2026 year, Class 9 and 10) plus fees: a monthly Tuition
 * head with an ৳800 rate for Class 10, a Class 10 Section A, an office clerk and a
 * teacher, and "now" set to 2026-10-15 (Asia/Dhaka) so the current month is October.
 */
trait BuildsFees
{
    use BuildsExams;

    protected Section $section10;

    protected FeeHead $tuition;

    protected User $office;

    protected User $teacher;

    protected function setUpFees(): void
    {
        $this->setUpExams();
        $this->travelTo('2026-10-15 06:00:00');

        $this->section10 = Section::factory()->create(['class_id' => $this->class10->id, 'shift_id' => $this->shift->id]);
        $this->tuition = FeeHead::factory()->create(['code' => 'TUITION', 'name_en' => 'Tuition Fee', 'kind' => FeeHead::KIND_MONTHLY]);
        $this->rate($this->tuition, $this->class10, '800.00');

        $this->office = $this->userWithRole('office');
        $this->teacher = $this->userWithRole('teacher');
    }

    protected function rate(FeeHead $head, Classes $class, string $amount, ?string $group = null, ?int $dueDay = null): FeeRate
    {
        return FeeRate::factory()->create([
            'fee_head_id' => $head->id,
            'class_id' => $class->id,
            'academic_year_id' => $this->year->id,
            'group' => $group,
            'amount' => $amount,
            'due_day' => $dueDay,
        ]);
    }

    /**
     * @return list<StudentEnrolment>
     */
    protected function enrolStudents(int $count, ?Section $section = null, ?string $group = null): array
    {
        $section ??= $this->section10;

        $next = (int) StudentEnrolment::where('section_id', $section->id)->max('roll_number') + 1;

        return array_map(fn (int $i) => $this->enrol($section, $group, null, $next + $i), range(0, $count - 1));
    }

    protected function waive(StudentEnrolment $enrolment, FeeHead $head, ?string $percent, ?string $fixed = null): StudentFeeWaiver
    {
        return StudentFeeWaiver::factory()->create([
            'student_id' => $enrolment->student_id,
            'academic_year_id' => $this->year->id,
            'fee_head_id' => $head->id,
            'percent' => $percent,
            'fixed_amount' => $fixed,
            'approved_by' => $this->admin->id,
        ]);
    }

    /** Generates the given month's dues as the admin. */
    protected function generateDues(array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->as($this->admin)->postJson('/api/fee-dues/generate', [
            'academic_year_id' => $this->year->id,
            'month' => '2026-10',
            ...$extra,
        ]);
    }

    /** Collects a payment as the office clerk. */
    protected function pay(StudentEnrolment|int $student, string $amount, string $method = 'cash', array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->as($this->office)->postJson('/api/fee-payments', [
            'student_id' => $student instanceof StudentEnrolment ? $student->student_id : $student,
            'amount' => $amount,
            'method' => $method,
            ...$extra,
        ]);
    }
}
