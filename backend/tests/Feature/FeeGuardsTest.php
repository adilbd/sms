<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Classes;
use App\Models\FeeDue;
use App\Models\Section;
use App\Repositories\Contracts\AcademicYearRepositoryInterface;
use App\Repositories\Contracts\ClassRepositoryInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFees;
use Tests\TestCase;

/**
 * The delete guards of classes, academic years and students now look at the new fee
 * tables (rates and dues; dues and payments).
 */
class FeeGuardsTest extends TestCase
{
    use BuildsFees, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFees();
    }

    public function test_a_class_with_a_fee_rate_cannot_be_deleted(): void
    {
        // Class 9 has no sections or students, so only the rate stands in the way.
        $this->rate($this->tuition, $this->class9, '900.00');

        $this->as($this->admin)->deleteJson("/api/classes/{$this->class9->id}")->assertStatus(409);
        $this->assertDatabaseHas('classes', ['id' => $this->class9->id, 'deleted_at' => null]);
    }

    public function test_the_class_and_year_guards_see_dues_even_without_rates(): void
    {
        $this->enrolStudents(1);
        $this->generateDues()->assertOk();
        $this->tuition->rates()->delete();

        // A class and a year with dues but no rates still count as having fee records.
        $this->assertTrue(app(ClassRepositoryInterface::class)->hasFeeRatesOrDues($this->class10));
        $this->assertTrue(app(AcademicYearRepositoryInterface::class)->hasFeeRatesOrDues($this->year));
        $this->assertFalse(app(ClassRepositoryInterface::class)->hasFeeRatesOrDues($this->class9));
        $this->assertFalse(app(AcademicYearRepositoryInterface::class)->hasFeeRatesOrDues(AcademicYear::factory()->create(['year' => 2027])));

        // The students and sections still block the delete too, with the same 409.
        $this->as($this->admin)->deleteJson("/api/classes/{$this->class10->id}")->assertStatus(409);
    }

    public function test_a_class_without_fees_can_still_be_deleted(): void
    {
        $class = Classes::factory()->create(['number' => 3]);

        $this->as($this->admin)->deleteJson("/api/classes/{$class->id}")->assertNoContent();
    }

    public function test_an_academic_year_with_a_fee_rate_cannot_be_deleted(): void
    {
        // The year has the Class 10 tuition rate and nothing else (no students, no exams).
        $this->year->update(['is_active' => false]);

        $this->as($this->admin)->deleteJson("/api/academic-years/{$this->year->id}")->assertStatus(409);

        $this->tuition->rates()->delete();
        $this->as($this->admin)->deleteJson("/api/academic-years/{$this->year->id}")->assertNoContent();
    }

    public function test_a_student_with_dues_or_payments_cannot_be_deleted(): void
    {
        [$withDues, $withPayment] = $this->enrolStudents(2);
        $free = $this->enrolStudents(1)[0];
        $this->generateDues()->assertOk();
        $this->pay($withPayment, '100.00')->assertCreated();
        FeeDue::where('student_id', $free->student_id)->delete();

        $this->as($this->admin)->deleteJson("/api/students/{$withDues->student_id}")->assertStatus(409);
        $this->as($this->admin)->deleteJson("/api/students/{$withPayment->student_id}")->assertStatus(409);
        $this->as($this->admin)->deleteJson("/api/students/{$free->student_id}")->assertNoContent();
    }

    public function test_a_section_still_deletes_without_students(): void
    {
        $section = Section::factory()->create(['class_id' => $this->class9->id, 'shift_id' => $this->shift->id]);

        $this->as($this->admin)->deleteJson("/api/sections/{$section->id}")->assertNoContent();
    }
}
