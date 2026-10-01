<?php

namespace Tests\Unit;

use App\Models\AcademicYear;
use App\Models\Classes;
use App\Models\Section;
use App\Models\Staff;
use App\Models\Subject;
use App\Models\SubjectAssignment;
use App\Models\User;
use App\Repositories\Contracts\AcademicYearRepositoryInterface;
use App\Repositories\Contracts\SectionRepositoryInterface;
use App\Repositories\Contracts\StaffRepositoryInterface;
use App\Repositories\Contracts\SubjectAssignmentRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\SubjectAssignmentService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Services are unit-tested against mocked repository interfaces, no database.
 */
class SubjectAssignmentServiceTest extends TestCase
{
    private function section(): Section
    {
        $section = new Section(['class_id' => 9, 'shift_id' => 4]);
        $section->id = 20;

        return $section;
    }

    private function staff(array $attributes = []): Staff
    {
        $staff = new Staff(['status' => Staff::STATUS_ACTIVE, 'category' => Staff::CATEGORY_TEACHER, ...$attributes]);
        $staff->id = 7;

        return $staff;
    }

    private function year(): AcademicYear
    {
        $year = new AcademicYear;
        $year->id = 3;

        return $year;
    }

    private function repos(array $o = []): void
    {
        $section = $o['section'] ?? $this->section();

        $this->mock(SubjectAssignmentRepositoryInterface::class, function (MockInterface $m) use ($o, $section) {
            $m->shouldReceive('lockClass')->andReturn(new Classes)->byDefault();
            $m->shouldReceive('lockSection')->andReturn($section)->byDefault();
            $m->shouldReceive('curriculumSubjectIds')->andReturn($o['curriculum'] ?? [11, 12])->byDefault();
            $m->shouldReceive('findFor')->andReturn($o['existing'] ?? null)->byDefault();
            $m->shouldReceive('userHoldsAssignment')->andReturn($o['holds'] ?? false)->byDefault();
            ($o['assignments'] ?? fn () => null)($m);
        });
        $this->mock(SectionRepositoryInterface::class, fn (MockInterface $m) => $m->shouldReceive('findOrFail')->andReturn($section)->byDefault());
        $this->mock(StaffRepositoryInterface::class, function (MockInterface $m) use ($o) {
            $m->shouldReceive('findOrFail')->andReturn($o['staff'] ?? $this->staff())->byDefault();
            $m->shouldReceive('belongsToShift')->andReturn($o['inShift'] ?? true)->byDefault();
        });
        $this->mock(AcademicYearRepositoryInterface::class, function (MockInterface $m) use ($o) {
            $m->shouldReceive('findActive')->andReturn(array_key_exists('active', $o) ? $o['active'] : $this->year())->byDefault();
            $m->shouldReceive('findOrFail')->andReturn($this->year())->byDefault();
        });
        $this->mock(UserRepositoryInterface::class, fn (MockInterface $m) => $m->shouldReceive('hasRole')->andReturn($o['admin'] ?? false)->byDefault());
    }

    /** @return array<string, list<string>> */
    private function errors(callable $call): array
    {
        try {
            $call();
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            return $e->errors();
        }
    }

    private function data(array $extra = []): array
    {
        return ['section_id' => 20, 'subject_id' => 11, 'staff_id' => 7, ...$extra];
    }

    public function test_create_fills_class_and_the_active_year_and_writes(): void
    {
        $this->repos(['assignments' => function (MockInterface $m) {
            $m->shouldReceive('create')->once()->with([
                'staff_id' => 7, 'subject_id' => 11, 'class_id' => 9, 'section_id' => 20, 'academic_year_id' => 3,
            ])->andReturn(new SubjectAssignment);
        }]);

        app(SubjectAssignmentService::class)->create($this->data());
    }

    public function test_an_inactive_teacher_is_refused(): void
    {
        $this->repos(['staff' => $this->staff(['status' => Staff::STATUS_RETIRED]), 'assignments' => fn ($m) => $m->shouldNotReceive('create')]);

        $this->assertSame(['staff_id'], array_keys($this->errors(fn () => app(SubjectAssignmentService::class)->create($this->data()))));
    }

    public function test_a_non_teacher_is_refused(): void
    {
        $this->repos(['staff' => $this->staff(['category' => Staff::CATEGORY_STAFF]), 'assignments' => fn ($m) => $m->shouldNotReceive('create')]);

        $this->assertSame(['staff_id'], array_keys($this->errors(fn () => app(SubjectAssignmentService::class)->create($this->data()))));
    }

    public function test_a_teacher_outside_the_sections_shift_is_refused(): void
    {
        $this->repos(['inShift' => false, 'assignments' => fn ($m) => $m->shouldNotReceive('create')]);

        $this->assertSame(['staff_id'], array_keys($this->errors(fn () => app(SubjectAssignmentService::class)->create($this->data()))));
    }

    public function test_a_subject_outside_the_curriculum_is_refused(): void
    {
        $this->repos(['curriculum' => [12], 'assignments' => fn ($m) => $m->shouldNotReceive('create')]);

        $this->assertSame(['subject_id'], array_keys($this->errors(fn () => app(SubjectAssignmentService::class)->create($this->data()))));
    }

    public function test_an_existing_assignment_for_the_subject_is_refused(): void
    {
        $this->repos(['existing' => new SubjectAssignment, 'assignments' => fn ($m) => $m->shouldNotReceive('create')]);

        $this->assertSame(['subject_id'], array_keys($this->errors(fn () => app(SubjectAssignmentService::class)->create($this->data()))));
    }

    public function test_no_active_year_is_refused_on_academic_year_id(): void
    {
        $this->repos(['active' => null, 'assignments' => fn ($m) => $m->shouldNotReceive('create')]);

        $this->assertSame(['academic_year_id'], array_keys($this->errors(fn () => app(SubjectAssignmentService::class)->create($this->data()))));
    }

    public function test_the_class_is_locked_before_the_section_and_both_before_any_write(): void
    {
        $this->repos(['assignments' => function (MockInterface $m) {
            $m->shouldReceive('lockClass')->once()->with(9)->ordered()->andReturn(new Classes);
            $m->shouldReceive('lockSection')->once()->ordered()->andReturn($this->section());
            $m->shouldReceive('create')->once()->ordered()->andReturn(new SubjectAssignment);
        }]);

        app(SubjectAssignmentService::class)->create($this->data());
    }

    public function test_the_curriculum_check_uses_the_sections_group(): void
    {
        $section = new Section(['class_id' => 9, 'shift_id' => 4, 'group' => 'science']);
        $section->id = 20;

        $this->repos(['section' => $section, 'assignments' => function (MockInterface $m) {
            $m->shouldReceive('curriculumSubjectIds')->once()->with(9, 'science')->andReturn([12]);
            $m->shouldNotReceive('create');
        }]);

        $errors = $this->errors(fn () => app(SubjectAssignmentService::class)->create($this->data()));

        $this->assertSame(['subject_id'], array_keys($errors));
        $this->assertStringContainsString('group', $errors['subject_id'][0]);
    }

    public function test_update_locks_the_class_before_the_section(): void
    {
        $this->repos(['assignments' => function (MockInterface $m) {
            $m->shouldReceive('lockClass')->once()->with(9)->ordered()->andReturn(new Classes);
            $m->shouldReceive('lockSection')->once()->ordered()->andReturn($this->section());
            $m->shouldReceive('update')->once()->ordered()->andReturn(new SubjectAssignment);
        }]);

        $assignment = new SubjectAssignment;
        $assignment->setRelation('section', $this->section());

        app(SubjectAssignmentService::class)->update($assignment, ['staff_id' => 7]);
    }

    public function test_bulk_locks_the_class_before_the_section(): void
    {
        $this->repos(['assignments' => function (MockInterface $m) {
            $m->shouldReceive('lockClass')->once()->with(9)->ordered()->andReturn(new Classes);
            $m->shouldReceive('lockSection')->once()->ordered()->andReturn($this->section());
            $m->shouldReceive('replaceForSection')->once()->ordered();
            $m->shouldReceive('forSectionAndYear')->andReturn(new Collection);
        }]);

        app(SubjectAssignmentService::class)->syncForSection($this->section(), ['academic_year_id' => 3, 'assignments' => []]);
    }

    public function test_list_defaults_to_the_active_year(): void
    {
        $this->repos(['assignments' => function (MockInterface $m) {
            $m->shouldReceive('paginate')->once()->with(['section_id' => 20, 'academic_year_id' => 3], 15)
                ->andReturn(new LengthAwarePaginator([], 0, 15));
        }]);

        app(SubjectAssignmentService::class)->list(['section_id' => 20], 15);
    }

    public function test_list_keeps_an_explicit_year_and_all_years_when_none_is_active(): void
    {
        $this->repos(['active' => null, 'assignments' => function (MockInterface $m) {
            $m->shouldReceive('paginate')->once()->with(['section_id' => 20], 15)->andReturn(new LengthAwarePaginator([], 0, 15));
            $m->shouldReceive('paginate')->once()->with(['academic_year_id' => 5], 15)->andReturn(new LengthAwarePaginator([], 0, 15));
        }]);

        app(SubjectAssignmentService::class)->list(['section_id' => 20], 15);
        app(SubjectAssignmentService::class)->list(['academic_year_id' => 5], 15);
    }

    // Bulk

    public function test_bulk_errors_are_keyed_per_row_and_nothing_is_written(): void
    {
        $this->repos(['inShift' => false, 'curriculum' => [11], 'assignments' => fn ($m) => $m->shouldNotReceive('replaceForSection')]);

        $errors = $this->errors(fn () => app(SubjectAssignmentService::class)->syncForSection($this->section(), [
            'academic_year_id' => 3,
            'assignments' => [
                ['subject_id' => 11, 'staff_id' => 7],
                ['subject_id' => 99, 'staff_id' => 7],
                ['subject_id' => 11, 'staff_id' => null],
            ],
        ]));

        $this->assertEqualsCanonicalizing(
            ['assignments.0.staff_id', 'assignments.1.subject_id', 'assignments.1.staff_id', 'assignments.2.subject_id'],
            array_keys($errors)
        );
    }

    public function test_bulk_replaces_with_the_non_null_rows_and_returns_the_fresh_list(): void
    {
        $this->repos(['assignments' => function (MockInterface $m) {
            $m->shouldReceive('replaceForSection')->once()->with(\Mockery::type(Section::class), 3, [11 => 7]);
            $m->shouldReceive('forSectionAndYear')->once()->andReturn(new Collection);
        }]);

        app(SubjectAssignmentService::class)->syncForSection($this->section(), [
            'academic_year_id' => 3,
            'assignments' => [['subject_id' => 11, 'staff_id' => 7], ['subject_id' => 12, 'staff_id' => null]],
        ]);
    }

    public function test_bulk_removal_is_allowed_for_a_subject_that_left_the_curriculum(): void
    {
        $this->repos(['curriculum' => [], 'assignments' => function (MockInterface $m) {
            $m->shouldReceive('replaceForSection')->once()->with(\Mockery::type(Section::class), 3, []);
            $m->shouldReceive('forSectionAndYear')->once()->andReturn(new Collection);
        }]);

        app(SubjectAssignmentService::class)->syncForSection($this->section(), [
            'academic_year_id' => 3, 'assignments' => [['subject_id' => 11, 'staff_id' => null]],
        ]);
    }

    // canEnterMarks

    public function test_an_admin_can_enter_marks_without_an_assignment_lookup(): void
    {
        $this->repos(['admin' => true, 'assignments' => fn ($m) => $m->shouldNotReceive('userHoldsAssignment')]);

        $this->assertTrue(app(SubjectAssignmentService::class)->canEnterMarks($this->user(), $this->section(), $this->subject(), $this->year()));
    }

    public function test_a_non_admin_can_enter_marks_only_with_the_assignment(): void
    {
        $this->repos(['holds' => true, 'assignments' => fn ($m) => $m->shouldReceive('userHoldsAssignment')->once()->with(5, 20, 11, 3)->andReturn(true)]);
        $this->assertTrue(app(SubjectAssignmentService::class)->canEnterMarks($this->user(), $this->section(), $this->subject(), $this->year()));

        $this->repos(['assignments' => fn ($m) => $m->shouldReceive('userHoldsAssignment')->once()->with(5, 20, 11, 3)->andReturn(false)]);
        $this->assertFalse(app(SubjectAssignmentService::class)->canEnterMarks($this->user(), $this->section(), $this->subject(), $this->year()));
    }

    private function user(): User
    {
        $user = new User;
        $user->id = 5;

        return $user;
    }

    private function subject(): Subject
    {
        $subject = new Subject;
        $subject->id = 11;

        return $subject;
    }
}
