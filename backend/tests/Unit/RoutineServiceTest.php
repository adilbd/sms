<?php

namespace Tests\Unit;

use App\Models\AcademicYear;
use App\Models\Classes;
use App\Models\Period;
use App\Models\RoutineSlot;
use App\Models\Section;
use App\Models\Staff;
use App\Models\SubjectAssignment;
use App\Models\User;
use App\Repositories\Contracts\AcademicYearRepositoryInterface;
use App\Repositories\Contracts\PeriodRepositoryInterface;
use App\Repositories\Contracts\RoutineRepositoryInterface;
use App\Repositories\Contracts\StaffRepositoryInterface;
use App\Repositories\Contracts\SubjectAssignmentRepositoryInterface;
use App\Services\InstituteSettingsService;
use App\Services\RoutineService;
use App\Services\TeacherScope;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Services are unit-tested against mocked repository interfaces, no database. Section 20
 * (Class 10, shift 4) has the periods 1 (08:00-08:45), 2 (08:45-09:30) and 3 (a break); the
 * curriculum is subjects 11 and 12; the weekly holiday is Friday.
 */
class RoutineServiceTest extends TestCase
{
    private function section(?string $group = null): Section
    {
        $section = new Section(['class_id' => 10, 'shift_id' => 4, 'name' => 'A', 'group' => $group]);
        $section->id = 20;
        $section->setRelation('class', new Classes(['name' => 'Class 10']));
        $section->setRelation('shift', new \App\Models\Shift(['name_en' => 'Morning']));

        return $section;
    }

    private function period(int $id, string $from, string $to, bool $break = false): Period
    {
        $period = new Period(['shift_id' => 4, 'number' => $id, 'start_time' => $from, 'end_time' => $to, 'is_break' => $break]);
        $period->id = $id;

        return $period;
    }

    private function teacher(string $status = Staff::STATUS_ACTIVE): Staff
    {
        $staff = new Staff(['name_en' => 'Rahim', 'status' => $status]);
        $staff->id = 7;

        return $staff;
    }

    private function clash(?int $staffId = 7, ?string $roomKey = null): RoutineSlot
    {
        $slot = new RoutineSlot(['day' => 'saturday', 'staff_id' => $staffId, 'room_key' => $roomKey]);
        $slot->setRelation('section', (new Section(['name' => 'B']))->setRelation('class', new Classes(['name' => 'Class 9'])));
        $slot->setRelation('period', $this->period(9, '08:30:00', '09:15:00'));

        return $slot;
    }

    /**
     * @param  array{assigned?: bool, staff?: ?Staff, teacherClash?: ?RoutineSlot, roomClash?: ?RoutineSlot, replace?: bool}  $o
     */
    private function mocks(array $o = []): void
    {
        $section = $this->section($o['group'] ?? null);
        $year = new AcademicYear;
        $year->id = 3;

        $this->mock(RoutineRepositoryInterface::class, function (MockInterface $m) use ($o, $section, $year) {
            $m->shouldReceive('lockSection')->andReturn($section)->byDefault();
            $m->shouldReceive('lockAcademicYear')->andReturn($year)->byDefault();
            $m->shouldReceive('otherSectionSlots')->andReturn(new Collection(array_values(array_filter([$o['teacherClash'] ?? null, $o['roomClash'] ?? null]))))->byDefault();
            $m->shouldReceive('forSectionAndYear')->andReturn(new Collection)->byDefault();
            if ($o['replace'] ?? false) {
                $m->shouldReceive('replaceForSection')->once();
            } else {
                $m->shouldNotReceive('replaceForSection');
            }
        });
        $this->mock(PeriodRepositoryInterface::class, fn (MockInterface $m) => $m->shouldReceive('forShift')->with(4)->andReturn(new Collection([
            $this->period(1, '08:00:00', '08:45:00'), $this->period(2, '08:45:00', '09:30:00'), $this->period(3, '09:30:00', '09:45:00', true),
        ]))->byDefault());
        $this->mock(SubjectAssignmentRepositoryInterface::class, function (MockInterface $m) use ($o) {
            $m->shouldReceive('curriculumSubjectIds')->andReturn([11, 12])->byDefault();
            $m->shouldReceive('forSectionAndYear')->andReturn(new Collection(($o['assigned'] ?? true)
                ? [new SubjectAssignment(['subject_id' => 11, 'staff_id' => 7]), new SubjectAssignment(['subject_id' => 12, 'staff_id' => 7]), new SubjectAssignment(['subject_id' => 99, 'staff_id' => 7])]
                : []))->byDefault();
        });
        $this->mock(AcademicYearRepositoryInterface::class, fn (MockInterface $m) => $m->shouldReceive('findActive')->andReturn($year)->byDefault());
        $this->mock(StaffRepositoryInterface::class, function (MockInterface $m) use ($o) {
            $m->shouldReceive('findManyByIds')->andReturn(new Collection(array_key_exists('staff', $o) ? array_filter([$o['staff']?->id => $o['staff']]) : [7 => $this->teacher()]))->byDefault();
            $m->shouldReceive('findByUserId')->andReturn(null)->byDefault();
        });
        $this->mock(InstituteSettingsService::class, fn (MockInterface $m) => $m->shouldReceive('weeklyHolidays')->andReturn(['friday'])->byDefault());
        $this->mock(TeacherScope::class, function (MockInterface $m) {
            $m->shouldReceive('sectionIdsFor')->andReturn(null)->byDefault();
            $m->shouldReceive('isScoped')->andReturn(false)->byDefault();
        });
    }

    /** @return array<string, list<string>> */
    private function errors(array $slots): array
    {
        try {
            app(RoutineService::class)->replaceForSection($this->section(), ['academic_year_id' => 3, 'slots' => $slots]);
        } catch (ValidationException $e) {
            return $e->errors();
        }

        $this->fail('Expected a ValidationException.');
    }

    private function cell(array $override = []): array
    {
        return ['day' => 'saturday', 'period_id' => 1, 'subject_id' => 11, 'staff_id' => 7, 'room' => 'Room 101', ...$override];
    }

    public function test_a_valid_grid_is_written_with_a_normalized_room_key(): void
    {
        $this->mocks(['replace' => true]);
        $this->app->make(RoutineRepositoryInterface::class)->shouldReceive('replaceForSection')->withArgs(function ($section, $yearId, $rows) {
            return $yearId === 3 && $rows === [
                ['day' => 'saturday', 'period_id' => 1, 'subject_id' => 11, 'staff_id' => 7, 'room' => 'Room 101', 'room_key' => 'room 101'],
                ['day' => 'sunday', 'period_id' => 2, 'subject_id' => 12, 'staff_id' => null, 'room' => null, 'room_key' => null],
            ];
        });

        app(RoutineService::class)->replaceForSection($this->section(), ['academic_year_id' => 3, 'slots' => [
            $this->cell(['room' => '  Room   101 ']),
            $this->cell(['day' => 'sunday', 'period_id' => 2, 'subject_id' => 12, 'staff_id' => null, 'room' => '  ']),
        ]]);
    }

    public function test_the_section_is_locked_before_the_year_inside_the_write(): void
    {
        $this->mocks(['replace' => true]);
        $repo = $this->app->make(RoutineRepositoryInterface::class);
        $repo->shouldReceive('lockSection')->once()->with(20)->globally()->ordered()->andReturn($this->section());
        $repo->shouldReceive('lockAcademicYear')->once()->with(3)->globally()->ordered()->andReturn(tap(new AcademicYear, fn ($y) => $y->id = 3));

        app(RoutineService::class)->replaceForSection($this->section(), ['academic_year_id' => 3, 'slots' => [$this->cell()]]);
    }

    public function test_a_break_a_foreign_period_a_holiday_and_a_duplicate_cell_are_refused_per_cell(): void
    {
        $this->mocks();

        $errors = $this->errors([
            $this->cell(['period_id' => 3]),
            $this->cell(['period_id' => 99]),
            $this->cell(['day' => 'friday']),
            $this->cell(['day' => 'sunday']),
            $this->cell(['day' => 'sunday']),
        ]);

        $this->assertSame(['slots.0.period_id', 'slots.1.period_id', 'slots.2.day', 'slots.4.period_id'], array_keys($errors));
    }

    public function test_a_subject_outside_the_curriculum_is_refused_for_a_section_with_a_group_too(): void
    {
        $this->mocks();
        $this->assertSame(['slots.0.subject_id'], array_keys($this->errors([$this->cell(['subject_id' => 99])])));
        $this->assertStringContainsString('class curriculum', $this->errors([$this->cell(['subject_id' => 99])])['slots.0.subject_id'][0]);
    }

    public function test_the_curriculum_is_read_for_the_sections_group(): void
    {
        $this->mocks(['group' => 'science', 'replace' => true]);
        $this->app->make(SubjectAssignmentRepositoryInterface::class)->shouldReceive('curriculumSubjectIds')->once()->with(10, 'science')->andReturn([11]);

        app(RoutineService::class)->replaceForSection($this->section('science'), ['academic_year_id' => 3, 'slots' => [$this->cell()]]);
    }

    public function test_a_teacher_who_is_not_assigned_or_not_active_is_refused(): void
    {
        $this->mocks(['assigned' => false]);
        $this->assertSame(['slots.0.staff_id'], array_keys($this->errors([$this->cell()])));

        $this->mocks(['staff' => $this->teacher(Staff::STATUS_RETIRED)]);
        $this->assertSame(['slots.0.staff_id'], array_keys($this->errors([$this->cell()])));

        $this->mocks(['staff' => null]);
        $this->assertSame(['slots.0.staff_id'], array_keys($this->errors([$this->cell()])));
    }

    public function test_a_teacher_clash_names_the_other_section_and_its_times(): void
    {
        $this->mocks(['teacherClash' => $this->clash()]);

        $message = $this->errors([$this->cell()])['slots.0.staff_id'][0];

        $this->assertStringContainsString('Rahim', $message);
        $this->assertStringContainsString('Class 9 B', $message);
        $this->assertStringContainsString('08:30-09:15', $message);
    }

    public function test_a_room_clash_compares_the_normalized_key(): void
    {
        $this->mocks(['roomClash' => $this->clash(null, 'room 101')]);

        $this->assertSame(['slots.0.room'], array_keys($this->errors([$this->cell(['staff_id' => null, 'room' => ' ROOM 101 '])])));
    }

    public function test_back_to_back_periods_and_other_days_do_not_clash(): void
    {
        $later = $this->clash();
        $later->period->start_time = '08:45:00';
        $later->period->end_time = '09:30:00';
        $this->mocks(['teacherClash' => $later, 'replace' => true]);

        // Period 1 ends at 08:45, exactly when the other section's class starts.
        app(RoutineService::class)->replaceForSection($this->section(), ['academic_year_id' => 3, 'slots' => [$this->cell(['room' => null])]]);

        $otherDay = $this->clash();
        $this->mocks(['teacherClash' => $otherDay, 'replace' => true]);
        app(RoutineService::class)->replaceForSection($this->section(), ['academic_year_id' => 3, 'slots' => [$this->cell(['day' => 'sunday', 'room' => null])]]);
    }

    public function test_a_cell_with_a_bad_period_is_not_checked_for_clashes(): void
    {
        $this->mocks(['teacherClash' => $this->clash()]);

        $this->assertSame(['slots.0.period_id'], array_keys($this->errors([$this->cell(['period_id' => 3])])));
    }

    public function test_the_grid_is_checked_with_one_load_of_each_kind_whatever_the_number_of_cells(): void
    {
        $this->mocks(['replace' => true]);
        $this->app->make(StaffRepositoryInterface::class)->shouldReceive('findManyByIds')->once()->with([7])->andReturn(new Collection([7 => $this->teacher()]));
        $this->app->make(SubjectAssignmentRepositoryInterface::class)->shouldReceive('forSectionAndYear')->once()->andReturn(new Collection([new SubjectAssignment(['subject_id' => 11, 'staff_id' => 7])]));
        $this->app->make(RoutineRepositoryInterface::class)->shouldReceive('otherSectionSlots')->once()->with(3, 20, [7], ['room 101'])->andReturn(new Collection);
        $this->app->make(RoutineRepositoryInterface::class)->shouldNotReceive('findTeacherClash');
        $this->app->make(RoutineRepositoryInterface::class)->shouldNotReceive('findRoomClash');

        app(RoutineService::class)->replaceForSection($this->section(), ['academic_year_id' => 3, 'slots' => [
            $this->cell(), $this->cell(['day' => 'sunday']), $this->cell(['day' => 'monday', 'period_id' => 2]),
        ]]);
    }

    public function test_a_teacher_may_only_read_their_own_week(): void
    {
        $this->mocks();
        $user = new User;
        $user->id = 5;
        $mine = $this->teacher();
        $other = tap($this->teacher(), fn ($s) => $s->id = 8);

        $this->mock(TeacherScope::class, fn (MockInterface $m) => $m->shouldReceive('isScoped')->with($user)->andReturn(true));
        $this->mock(StaffRepositoryInterface::class, fn (MockInterface $m) => $m->shouldReceive('findByUserId')->with(5)->andReturn($mine));
        $this->mock(AcademicYearRepositoryInterface::class, fn (MockInterface $m) => $m->shouldReceive('findActive')->andReturn(null));

        $this->assertNull(app(RoutineService::class)->forTeacher($mine, null, $user)['academic_year']);

        try {
            app(RoutineService::class)->forTeacher($other, null, $user);
            $this->fail('Expected a 403.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_a_teacher_may_only_read_sections_they_teach_or_lead(): void
    {
        $this->mocks();
        $user = new User;
        $this->mock(TeacherScope::class, fn (MockInterface $m) => $m->shouldReceive('sectionIdsFor')->andReturn([20]));

        $this->assertSame(20, app(RoutineService::class)->forSection($this->section(), null, $user)['section']->id);

        $other = $this->section();
        $other->id = 21;

        try {
            app(RoutineService::class)->forSection($other, null, $user);
            $this->fail('Expected a 403.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_school_days_are_the_week_minus_the_weekly_holidays(): void
    {
        $this->mocks();

        $this->assertSame(['saturday', 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday'], app(RoutineService::class)->schoolDays());
    }
}
