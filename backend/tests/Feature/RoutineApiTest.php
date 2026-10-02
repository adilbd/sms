<?php

namespace Tests\Feature;

use App\Models\ClassSection;
use App\Models\RoutineSlot;
use App\Models\Section;
use App\Models\Staff;
use App\Models\Student;
use App\Models\StudentEnrolment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFees;
use Tests\Concerns\BuildsRoutines;
use Tests\TestCase;

/**
 * The class routine API: the grid save and every rule and clash check, replace semantics,
 * and who may read which routine. Fridays are the weekly holiday by default.
 */
class RoutineApiTest extends TestCase
{
    use BuildsFees, BuildsRoutines, RefreshDatabase;

    private Staff $banglaTeacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFees();
        $this->setUpRoutines();
        $this->curriculum($this->class10, $this->physics, null, 'compulsory');

        $this->banglaTeacher = $this->teacherFor($this->section10, $this->bangla);
    }

    private function url(Section $section, string $query = ''): string
    {
        return "/api/routines/sections/{$section->id}{$query}";
    }

    // Saving and reading

    public function test_saving_a_grid_stores_it_and_returns_the_routine(): void
    {
        $this->saveRoutine($this->section10, [
            $this->cell($this->morning[1], 'saturday', $this->bangla, $this->banglaTeacher, ' Room  101 '),
            $this->cell($this->morning[2], 'saturday', $this->physics),
        ])
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'kind', 'academic_year' => ['id', 'year'], 'section' => ['id', 'class' => ['id', 'name'], 'shift' => ['id']],
                'days', 'periods' => [['id', 'number', 'start_time', 'end_time', 'is_break']],
                'slots' => [['id', 'day', 'period_id', 'subject_id', 'staff_id', 'room', 'subject' => ['id', 'name'], 'period' => ['id'], 'staff' => ['id', 'name_en']]],
            ], 'message'])
            ->assertJsonPath('data.kind', 'section')
            ->assertJsonCount(4, 'data.periods')
            ->assertJsonCount(2, 'data.slots')
            ->assertJsonPath('data.slots.0.room', 'Room 101')
            ->assertJsonPath('data.slots.1.staff', null);

        $this->assertDatabaseHas('routine_slots', ['section_id' => $this->section10->id, 'room_key' => 'room 101', 'staff_id' => $this->banglaTeacher->id]);
        $this->as($this->admin)->getJson($this->url($this->section10))
            ->assertOk()->assertJsonCount(2, 'data.slots')->assertJsonPath('data.days', ['saturday', 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday']);
    }

    public function test_saving_replaces_the_whole_grid_and_cells_left_out_are_removed(): void
    {
        $this->saveRoutine($this->section10, [
            $this->cell($this->morning[1], 'saturday', $this->bangla),
            $this->cell($this->morning[2], 'saturday', $this->physics),
            $this->cell($this->morning[1], 'sunday', $this->bangla),
        ])->assertOk();
        $other = RoutineSlot::factory()->create(['academic_year_id' => $this->year->id, 'section_id' => $this->section9->id, 'period_id' => $this->morning[1]->id, 'subject_id' => $this->bangla->id]);

        $this->saveRoutine($this->section10, [
            $this->cell($this->morning[2], 'monday', $this->physics),
        ])->assertOk()->assertJsonCount(1, 'data.slots')->assertJsonPath('data.slots.0.day', 'monday');

        $this->assertSame(1, $this->slotCount($this->section10));
        $this->assertDatabaseHas('routine_slots', ['id' => $other->id]);

        $this->saveRoutine($this->section10, [])->assertOk()->assertJsonCount(0, 'data.slots');
        $this->assertSame(0, $this->slotCount($this->section10));
    }

    public function test_a_failed_save_leaves_the_old_grid_in_place(): void
    {
        $this->saveRoutine($this->section10, [$this->cell($this->morning[1], 'saturday', $this->bangla)])->assertOk();

        $this->saveRoutine($this->section10, [
            $this->cell($this->morning[2], 'saturday', $this->bangla),
            $this->cell($this->morning[3], 'saturday', $this->bangla),
        ])->assertUnprocessable();

        $this->assertDatabaseHas('routine_slots', ['section_id' => $this->section10->id, 'period_id' => $this->morning[1]->id]);
        $this->assertSame(1, $this->slotCount($this->section10));
    }

    public function test_the_read_defaults_to_the_active_year_and_takes_a_year(): void
    {
        $this->saveRoutine($this->section10, [$this->cell($this->morning[1], 'saturday', $this->bangla)])->assertOk();
        $past = \App\Models\AcademicYear::factory()->create(['year' => 2025]);

        $this->as($this->admin)->getJson($this->url($this->section10, "?academic_year_id={$past->id}"))
            ->assertOk()->assertJsonPath('data.academic_year.id', $past->id)->assertJsonCount(0, 'data.slots');
        $this->as($this->admin)->getJson($this->url($this->section10, '?academic_year_id=999999'))->assertUnprocessable();
    }

    // Validation

    public function test_the_payload_is_validated(): void
    {
        $this->as($this->admin)->putJson($this->url($this->section10), [])
            ->assertUnprocessable()->assertJsonValidationErrors(['academic_year_id', 'slots']);

        $this->saveRoutine($this->section10, [['day' => 'funday', 'period_id' => 0, 'subject_id' => 0]])
            ->assertUnprocessable()->assertJsonValidationErrors(['slots.0.day', 'slots.0.period_id', 'slots.0.subject_id']);

        $this->saveRoutine($this->section10, [['day' => 'saturday', 'period_id' => $this->morning[1]->id, 'subject_id' => $this->bangla->id, 'room' => str_repeat('x', 51)]])
            ->assertUnprocessable()->assertJsonValidationErrors(['slots.0.room']);
    }

    public function test_a_break_period_cannot_hold_a_subject(): void
    {
        $this->saveRoutine($this->section10, [$this->cell($this->morning[3], 'saturday', $this->bangla)])
            ->assertUnprocessable()->assertJsonValidationErrors(['slots.0.period_id']);
    }

    public function test_a_period_from_another_shift_is_refused(): void
    {
        $this->saveRoutine($this->section10, [$this->cell($this->dayPeriods[1], 'saturday', $this->bangla)])
            ->assertUnprocessable()->assertJsonValidationErrors(['slots.0.period_id']);
    }

    public function test_a_weekly_holiday_is_refused_and_a_changed_holiday_is_honoured(): void
    {
        $this->saveRoutine($this->section10, [$this->cell($this->morning[1], 'friday', $this->bangla)])
            ->assertUnprocessable()->assertJsonValidationErrors(['slots.0.day']);

        $this->as($this->admin)->putJson('/api/settings/institute', ['weekly_holidays' => ['thursday', 'friday']])->assertOk();

        $this->saveRoutine($this->section10, [$this->cell($this->morning[1], 'thursday', $this->bangla)])
            ->assertUnprocessable()->assertJsonValidationErrors(['slots.0.day']);
        $this->as($this->admin)->getJson($this->url($this->section10))->assertJsonPath('data.days', ['saturday', 'sunday', 'monday', 'tuesday', 'wednesday']);
    }

    public function test_the_same_cell_twice_is_refused(): void
    {
        $this->saveRoutine($this->section10, [
            $this->cell($this->morning[1], 'saturday', $this->bangla),
            $this->cell($this->morning[1], 'saturday', $this->physics),
        ])->assertUnprocessable()->assertJsonValidationErrors(['slots.1.period_id']);
    }

    public function test_a_subject_outside_the_curriculum_is_refused(): void
    {
        // Higher Math is only in Class 9's science curriculum.
        $this->saveRoutine($this->section10, [$this->cell($this->morning[1], 'saturday', $this->higherMath)])
            ->assertUnprocessable()->assertJsonValidationErrors(['slots.0.subject_id']);
    }

    public function test_a_subject_for_another_group_is_refused(): void
    {
        $business = Section::factory()->create(['class_id' => $this->class9->id, 'shift_id' => $this->shift->id, 'group' => 'business_studies']);
        $science = Section::factory()->create(['class_id' => $this->class9->id, 'shift_id' => $this->shift->id, 'group' => 'science']);

        // Physics is a science-only subject: refused for Business Studies, fine for Science.
        $this->saveRoutine($business, [$this->cell($this->morning[1], 'saturday', $this->physics)])
            ->assertUnprocessable()->assertJsonValidationErrors(['slots.0.subject_id']);
        $this->saveRoutine($science, [$this->cell($this->morning[1], 'saturday', $this->physics)])->assertOk();
        // Bangla is class-wide, so every group may have it.
        $this->saveRoutine($business, [$this->cell($this->morning[1], 'saturday', $this->bangla)])->assertOk();
    }

    public function test_a_teacher_not_assigned_to_the_subject_is_refused(): void
    {
        $unassigned = Staff::factory()->create();
        $unassigned->shifts()->attach($this->shift->id);

        // Not assigned at all; assigned to Bangla but sent with Physics; assigned in a different section.
        $this->saveRoutine($this->section10, [$this->cell($this->morning[1], 'saturday', $this->bangla, $unassigned)])
            ->assertUnprocessable()->assertJsonValidationErrors(['slots.0.staff_id']);
        $this->saveRoutine($this->section10, [$this->cell($this->morning[1], 'saturday', $this->physics, $this->banglaTeacher)])
            ->assertUnprocessable()->assertJsonValidationErrors(['slots.0.staff_id']);
        $this->saveRoutine($this->section9, [$this->cell($this->morning[1], 'saturday', $this->bangla, $this->banglaTeacher)])
            ->assertUnprocessable()->assertJsonValidationErrors(['slots.0.staff_id']);
    }

    public function test_an_inactive_teacher_is_refused(): void
    {
        $this->banglaTeacher->update(['status' => Staff::STATUS_RETIRED, 'leaving_date' => '2026-01-01']);

        $this->saveRoutine($this->section10, [$this->cell($this->morning[1], 'saturday', $this->bangla, $this->banglaTeacher)])
            ->assertUnprocessable()->assertJsonValidationErrors(['slots.0.staff_id']);
    }

    public function test_every_problem_is_reported_per_cell_in_one_response(): void
    {
        $response = $this->saveRoutine($this->section10, [
            $this->cell($this->morning[3], 'saturday', $this->bangla),
            $this->cell($this->morning[1], 'friday', $this->higherMath),
            $this->cell($this->morning[2], 'sunday', $this->bangla),
        ])->assertUnprocessable();

        $response->assertJsonValidationErrors(['slots.0.period_id', 'slots.1.day', 'slots.1.subject_id']);
        $this->assertArrayNotHasKey('slots.2.period_id', $response->json('errors'));
    }

    // Clashes

    public function test_the_same_teacher_in_two_sections_at_the_same_time_is_refused_naming_the_other_section(): void
    {
        $this->assign($this->banglaTeacher, $this->section9, $this->bangla);
        $this->saveRoutine($this->section9, [$this->cell($this->morning[1], 'saturday', $this->bangla, $this->banglaTeacher)])->assertOk();

        $response = $this->saveRoutine($this->section10, [$this->cell($this->morning[1], 'saturday', $this->bangla, $this->banglaTeacher)])
            ->assertUnprocessable()->assertJsonValidationErrors(['slots.0.staff_id']);

        $this->assertStringContainsString('Class 9', $response->json('errors')['slots.0.staff_id'][0]);
        $this->assertSame(0, $this->slotCount($this->section10));
    }

    public function test_the_same_teacher_at_non_overlapping_times_is_fine(): void
    {
        $this->assign($this->banglaTeacher, $this->section9, $this->bangla);
        $this->saveRoutine($this->section9, [$this->cell($this->morning[1], 'saturday', $this->bangla, $this->banglaTeacher)])->assertOk();

        // The next period (back to back), another day, and the 4th period.
        $this->saveRoutine($this->section10, [
            $this->cell($this->morning[2], 'saturday', $this->bangla, $this->banglaTeacher),
            $this->cell($this->morning[1], 'sunday', $this->bangla, $this->banglaTeacher),
            $this->cell($this->morning[4], 'saturday', $this->bangla, $this->banglaTeacher),
        ])->assertOk()->assertJsonCount(3, 'data.slots');
    }

    public function test_the_same_teacher_across_shifts_at_overlapping_times_is_refused(): void
    {
        // The day shift's period 1 (08:30-09:15) overlaps the morning's periods 1 and 2.
        $this->assign($this->banglaTeacher, $this->dayShiftSection, $this->bangla);
        $this->saveRoutine($this->dayShiftSection, [$this->cell($this->dayPeriods[1], 'saturday', $this->bangla, $this->banglaTeacher)])->assertOk();

        foreach ([1, 2] as $number) {
            $this->saveRoutine($this->section10, [$this->cell($this->morning[$number], 'saturday', $this->bangla, $this->banglaTeacher)])
                ->assertUnprocessable()->assertJsonValidationErrors(['slots.0.staff_id']);
        }

        // Period 4 (09:45) is after 09:15, and the day shift's period 2 (10:30) is after the morning's.
        $this->saveRoutine($this->section10, [$this->cell($this->morning[4], 'saturday', $this->bangla, $this->banglaTeacher)])->assertOk();
        $this->saveRoutine($this->dayShiftSection, [
            $this->cell($this->dayPeriods[1], 'saturday', $this->bangla, $this->banglaTeacher),
            $this->cell($this->dayPeriods[2], 'saturday', $this->bangla, $this->banglaTeacher),
        ])->assertOk();
    }

    public function test_the_same_room_at_the_same_time_is_refused_ignoring_case_and_spaces(): void
    {
        $this->saveRoutine($this->section9, [$this->cell($this->morning[1], 'saturday', $this->bangla, null, 'room 101')])->assertOk();

        $response = $this->saveRoutine($this->section10, [$this->cell($this->morning[1], 'saturday', $this->bangla, null, 'Room 101 ')])
            ->assertUnprocessable()->assertJsonValidationErrors(['slots.0.room']);
        $this->assertStringContainsString('Class 9', $response->json('errors')['slots.0.room'][0]);

        // A different room, another day or a non-overlapping period is fine.
        $this->saveRoutine($this->section10, [
            $this->cell($this->morning[1], 'saturday', $this->bangla, null, 'Room 102'),
            $this->cell($this->morning[1], 'sunday', $this->bangla, null, 'Room 101'),
            $this->cell($this->morning[2], 'saturday', $this->bangla, null, 'ROOM 101'),
        ])->assertOk();
    }

    public function test_a_room_also_clashes_across_shifts_with_overlapping_times(): void
    {
        $this->saveRoutine($this->dayShiftSection, [$this->cell($this->dayPeriods[1], 'saturday', $this->bangla, null, 'Lab')])->assertOk();

        $this->saveRoutine($this->section10, [$this->cell($this->morning[2], 'saturday', $this->bangla, null, 'lab')])
            ->assertUnprocessable()->assertJsonValidationErrors(['slots.0.room']);
    }

    public function test_a_long_room_name_with_characters_that_lengthen_when_lowercased_is_stored(): void
    {
        $room = str_repeat('İ', 50);

        $this->saveRoutine($this->section10, [$this->cell($this->morning[1], 'saturday', $this->bangla, null, $room)])->assertOk();

        $this->assertDatabaseHas('routine_slots', ['section_id' => $this->section10->id, 'room' => $room]);
    }

    public function test_removing_a_teacher_from_a_subject_clears_only_their_slots_for_that_subject(): void
    {
        $physicsTeacher = $this->teacherFor($this->section10, $this->physics);
        $this->assign($this->banglaTeacher, $this->section10, $this->physics);
        $otherSection = $this->teacherFor($this->section9, $this->bangla);
        $this->assign($this->banglaTeacher, $this->section9, $this->bangla);

        $this->saveRoutine($this->section10, [
            $this->cell($this->morning[1], 'saturday', $this->bangla, $this->banglaTeacher, 'R1'),
            $this->cell($this->morning[2], 'saturday', $this->physics, $this->banglaTeacher),
            $this->cell($this->morning[1], 'sunday', $this->physics, $physicsTeacher),
        ])->assertOk();
        $this->saveRoutine($this->section9, [$this->cell($this->morning[4], 'saturday', $this->bangla, $this->banglaTeacher)])->assertOk();

        $assignment = \App\Models\SubjectAssignment::where(['section_id' => $this->section10->id, 'subject_id' => $this->bangla->id, 'staff_id' => $this->banglaTeacher->id])->firstOrFail();
        $this->as($this->admin)->deleteJson("/api/subject-assignments/{$assignment->id}")->assertNoContent();

        $slot = fn (Section $section, $period, string $day) => RoutineSlot::where(['section_id' => $section->id, 'period_id' => $period->id, 'day' => $day])->firstOrFail();

        $cleared = $slot($this->section10, $this->morning[1], 'saturday');
        $this->assertNull($cleared->staff_id);
        $this->assertSame($this->bangla->id, $cleared->subject_id);
        $this->assertSame('R1', $cleared->room);
        $this->assertSame($this->banglaTeacher->id, $slot($this->section10, $this->morning[2], 'saturday')->staff_id);
        $this->assertSame($physicsTeacher->id, $slot($this->section10, $this->morning[1], 'sunday')->staff_id);
        $this->assertSame($this->banglaTeacher->id, $slot($this->section9, $this->morning[4], 'saturday')->staff_id);

        // The grid, as the editor now shows it, still saves.
        $this->saveRoutine($this->section10, [
            $this->cell($this->morning[1], 'saturday', $this->bangla, null, 'R1'),
            $this->cell($this->morning[2], 'saturday', $this->physics, $this->banglaTeacher),
            $this->cell($this->morning[1], 'sunday', $this->physics, $physicsTeacher),
        ])->assertOk();
    }

    public function test_replacing_a_sections_teachers_clears_slots_of_teachers_left_out(): void
    {
        $this->saveRoutine($this->section10, [$this->cell($this->morning[1], 'saturday', $this->bangla, $this->banglaTeacher)])->assertOk();

        $this->as($this->admin)->putJson("/api/sections/{$this->section10->id}/subject-teachers", [
            'academic_year_id' => $this->year->id,
            'assignments' => [['subject_id' => $this->bangla->id, 'staff_ids' => []]],
        ])->assertOk();

        $this->assertNull(RoutineSlot::where('section_id', $this->section10->id)->firstOrFail()->staff_id);
    }

    public function test_resaving_a_section_with_its_own_old_cells(): void
    {
        $cells = [$this->cell($this->morning[1], 'saturday', $this->bangla, $this->banglaTeacher, 'Room 101')];

        $this->saveRoutine($this->section10, $cells)->assertOk();
        $this->saveRoutine($this->section10, $cells)->assertOk();
    }

    public function test_moving_a_used_period_into_a_clash_is_refused(): void
    {
        $this->assign($this->banglaTeacher, $this->dayShiftSection, $this->bangla);
        $this->saveRoutine($this->section10, [$this->cell($this->morning[1], 'saturday', $this->bangla, $this->banglaTeacher)])->assertOk();
        $this->saveRoutine($this->dayShiftSection, [$this->cell($this->dayPeriods[2], 'saturday', $this->bangla, $this->banglaTeacher)])->assertOk();

        // Moving the day shift's 10:30 period to 08:30 would double-book the teacher.
        $this->as($this->admin)->putJson("/api/periods/{$this->dayPeriods[2]->id}", ['start_time' => '08:30', 'end_time' => '09:15'])
            ->assertUnprocessable()->assertJsonValidationErrors(['start_time']);
        // A harmless move works.
        $this->as($this->admin)->putJson("/api/periods/{$this->dayPeriods[2]->id}", ['start_time' => '11:00', 'end_time' => '11:45'])->assertOk();
    }

    // Authorization

    public function test_routine_routes_require_authentication_and_the_class_permissions(): void
    {
        $this->getJson($this->url($this->section10))->assertUnauthorized();
        $this->putJson($this->url($this->section10), ['academic_year_id' => $this->year->id, 'slots' => []])->assertUnauthorized();
        $this->getJson("/api/routines/teachers/{$this->banglaTeacher->id}")->assertUnauthorized();

        // Students and guardians hold no class permissions.
        foreach (['student', 'parent'] as $role) {
            $user = $this->userWithRole($role);
            $this->as($user)->getJson($this->url($this->section10))->assertForbidden();
            $this->as($user)->putJson($this->url($this->section10), ['academic_year_id' => $this->year->id, 'slots' => []])->assertForbidden();
            $this->as($user)->getJson("/api/routines/teachers/{$this->banglaTeacher->id}")->assertForbidden();
        }

        // Editing needs edit-classes: teachers and the office only read.
        $this->saveRoutine($this->section10, [], $this->teacher)->assertForbidden();
        $this->saveRoutine($this->section10, [], $this->office)->assertForbidden();
        $this->as($this->office)->getJson($this->url($this->section10))->assertOk();
    }

    public function test_not_found_for_an_unknown_section_or_teacher(): void
    {
        $this->as($this->admin)->getJson('/api/routines/sections/999999')->assertNotFound();
        $this->as($this->admin)->putJson('/api/routines/sections/999999', ['academic_year_id' => $this->year->id, 'slots' => []])->assertNotFound();
        $this->as($this->admin)->getJson('/api/routines/teachers/999999')->assertNotFound();
        $this->as($this->admin)->getJson('/api/routines/sections/1abc')->assertNotFound();
    }

    public function test_a_teacher_reads_only_their_own_week_and_an_admin_reads_anyones(): void
    {
        $login = $this->userWithRole('teacher');
        $mine = $this->teacherFor($this->section10, $this->physics, [], $login);
        $this->saveRoutine($this->section10, [
            $this->cell($this->morning[1], 'saturday', $this->physics, $mine, 'Lab'),
            $this->cell($this->morning[2], 'saturday', $this->bangla, $this->banglaTeacher),
        ])->assertOk();

        $this->as($login)->getJson("/api/routines/teachers/{$mine->id}")
            ->assertOk()
            ->assertJsonStructure(['data' => ['kind', 'academic_year' => ['id'], 'staff' => ['id', 'name_en'], 'days', 'slots' => [['day', 'subject' => ['name'], 'period' => ['start_time'], 'section' => ['id', 'class' => ['name']]]]]])
            ->assertJsonPath('data.kind', 'teacher')
            ->assertJsonCount(1, 'data.slots')
            ->assertJsonPath('data.slots.0.room', 'Lab');

        $this->as($login)->getJson("/api/routines/teachers/{$this->banglaTeacher->id}")->assertForbidden();
        $this->as($this->admin)->getJson("/api/routines/teachers/{$this->banglaTeacher->id}")->assertOk()->assertJsonCount(1, 'data.slots');
        $this->as($this->office)->getJson("/api/routines/teachers/{$this->banglaTeacher->id}")->assertOk();

        // A teacher with no staff link can't read any.
        $this->as($this->teacher)->getJson("/api/routines/teachers/{$mine->id}")->assertForbidden();
    }

    public function test_a_teacher_reads_a_section_routine_only_for_sections_they_teach_or_lead(): void
    {
        $login = $this->userWithRole('teacher');
        $mine = $this->teacherFor($this->section10, $this->physics, [], $login);
        $this->saveRoutine($this->section10, [$this->cell($this->morning[1], 'saturday', $this->physics, $mine)])->assertOk();

        $this->as($login)->getJson($this->url($this->section10))->assertOk()->assertJsonCount(1, 'data.slots');
        $this->as($login)->getJson($this->url($this->section9))->assertForbidden();

        // Leading a section counts too.
        ClassSection::create(['class_id' => $this->section9->class_id, 'section_id' => $this->section9->id, 'academic_year_id' => $this->year->id, 'staff_id' => $mine->id, 'is_main' => true]);
        $this->as($login)->getJson($this->url($this->section9))->assertOk();
    }

    // /api/my/routine

    /** @return array{0: User, 1: Student, 2: StudentEnrolment} */
    private function studentIn(Section $section, ?User $guardian = null): array
    {
        $login = $this->userWithRole('student');
        $enrolment = $this->enrol($section, null, null, (int) StudentEnrolment::max('roll_number') + 1);
        $enrolment->student->update(['user_id' => $login->id, 'guardian_user_id' => $guardian?->id]);

        return [$login, $enrolment->student, $enrolment];
    }

    public function test_a_student_gets_their_own_sections_routine_only(): void
    {
        [$login] = $this->studentIn($this->section10);
        $this->saveRoutine($this->section10, [$this->cell($this->morning[1], 'saturday', $this->bangla, $this->banglaTeacher)])->assertOk();
        $this->saveRoutine($this->section9, [$this->cell($this->morning[2], 'sunday', $this->bangla)])->assertOk();

        $this->as($login)->getJson('/api/my/routine')
            ->assertOk()
            ->assertJsonPath('data.kind', 'section')
            ->assertJsonPath('data.section.id', $this->section10->id)
            ->assertJsonCount(1, 'data.slots')
            ->assertJsonPath('data.slots.0.subject_id', $this->bangla->id);

        // `?student=` is ignored for a student.
        [, $other] = $this->studentIn($this->section9);
        $this->as($login)->getJson("/api/my/routine?student={$other->id}")->assertOk()->assertJsonPath('data.section.id', $this->section10->id);
    }

    public function test_a_guardian_gets_each_childs_routine_and_not_another_familys(): void
    {
        $guardian = $this->userWithRole('parent');
        [, $first] = $this->studentIn($this->section10, $guardian);
        [, $second] = $this->studentIn($this->section9, $guardian);
        [, $stranger] = $this->studentIn($this->section9, $this->userWithRole('parent'));
        $this->saveRoutine($this->section10, [$this->cell($this->morning[1], 'saturday', $this->bangla)])->assertOk();
        $this->saveRoutine($this->section9, [$this->cell($this->morning[1], 'sunday', $this->bangla), $this->cell($this->morning[2], 'sunday', $this->bangla)])->assertOk();

        $this->as($guardian)->getJson("/api/my/routine?student={$first->id}")->assertOk()
            ->assertJsonPath('data.section.id', $this->section10->id)->assertJsonCount(1, 'data.slots');
        $this->as($guardian)->getJson("/api/my/routine?student={$second->id}")->assertOk()
            ->assertJsonPath('data.section.id', $this->section9->id)->assertJsonCount(2, 'data.slots');
        // Without `student` the first child is used.
        $firstChild = $first->student_id < $second->student_id ? $first : $second;
        $this->as($guardian)->getJson('/api/my/routine')->assertOk()
            ->assertJsonPath('data.section.id', $firstChild->currentEnrolment->section_id);
        $this->as($guardian)->getJson("/api/my/routine?student={$stranger->id}")->assertForbidden();
        $this->as($guardian)->getJson('/api/my/routine?student=999999')->assertForbidden();
    }

    public function test_a_teacher_gets_their_own_week_from_my_routine(): void
    {
        $login = $this->userWithRole('teacher');
        $mine = $this->teacherFor($this->section10, $this->physics, [], $login);
        $this->saveRoutine($this->section10, [
            $this->cell($this->morning[1], 'saturday', $this->physics, $mine),
            $this->cell($this->morning[2], 'saturday', $this->bangla, $this->banglaTeacher),
        ])->assertOk();

        $this->as($login)->getJson('/api/my/routine')
            ->assertOk()->assertJsonPath('data.kind', 'teacher')->assertJsonPath('data.staff.id', $mine->id)->assertJsonCount(1, 'data.slots');

        // A teacher without a staff link gets an empty week.
        $this->as($this->teacher)->getJson('/api/my/routine')
            ->assertOk()->assertJsonPath('data.staff', null)->assertJsonCount(0, 'data.slots');
    }

    public function test_my_routine_is_closed_to_other_roles_and_guests(): void
    {
        $this->getJson('/api/my/routine')->assertUnauthorized();
        $this->as($this->admin)->getJson('/api/my/routine')->assertForbidden();
        $this->as($this->office)->getJson('/api/my/routine')->assertForbidden();
    }

    public function test_a_student_without_an_enrolment_gets_an_empty_routine(): void
    {
        $login = $this->userWithRole('student');
        Student::factory()->create(['user_id' => $login->id]);

        $this->as($login)->getJson('/api/my/routine')->assertOk()->assertJsonPath('data.section', null)->assertJsonCount(0, 'data.slots');
    }

    // Delete guards

    public function test_a_section_staff_member_subject_and_year_in_a_routine_cannot_be_deleted(): void
    {
        $this->saveRoutine($this->section10, [$this->cell($this->morning[1], 'saturday', $this->bangla, $this->banglaTeacher)])->assertOk();

        $this->as($this->admin)->deleteJson("/api/sections/{$this->section10->id}")->assertStatus(409);
        $this->as($this->admin)->deleteJson("/api/staff/{$this->banglaTeacher->id}")->assertStatus(409);
        $this->as($this->admin)->deleteJson("/api/subjects/{$this->bangla->id}")->assertStatus(409);
        $this->year->update(['is_active' => false]);
        $this->as($this->admin)->deleteJson("/api/academic-years/{$this->year->id}")->assertStatus(409);
    }
}
