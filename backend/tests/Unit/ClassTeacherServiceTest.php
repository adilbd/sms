<?php

namespace Tests\Unit;

use App\Models\ClassSection;
use App\Models\Section;
use App\Models\Staff;
use App\Repositories\Contracts\ClassTeacherRepositoryInterface;
use App\Repositories\Contracts\StaffRepositoryInterface;
use App\Services\ClassTeacherService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * Services are unit-tested against a mocked repository interface — no database.
 */
class ClassTeacherServiceTest extends TestCase
{
    public function test_assign_with_null_staff_unassigns(): void
    {
        $section = new Section;
        $section->id = 1;
        $section->shift_id = 2;

        $this->mock(ClassTeacherRepositoryInterface::class, function (MockInterface $mock) use ($section) {
            $mock->shouldReceive('deleteForSectionAndYear')->once()->with($section, 3);
        });
        $this->mock(StaffRepositoryInterface::class, fn (MockInterface $mock) => $mock->shouldNotReceive('findOrFail'));

        $result = app(ClassTeacherService::class)->assign($section, ['academic_year_id' => 3, 'staff_id' => null]);

        $this->assertNull($result->staff);
        $this->assertSame(1, $result->section_id);
        $this->assertSame(3, $result->academic_year_id);
    }

    public function test_assign_rejects_an_inactive_staff_member(): void
    {
        $section = new Section;
        $section->id = 1;
        $section->shift_id = 2;
        $staff = new Staff(['status' => Staff::STATUS_RETIRED, 'category' => Staff::CATEGORY_TEACHER]);
        $staff->id = 5;

        $this->mock(StaffRepositoryInterface::class, function (MockInterface $mock) use ($staff) {
            $mock->shouldReceive('findOrFail')->once()->with(5)->andReturn($staff);
        });
        $this->mock(ClassTeacherRepositoryInterface::class, fn (MockInterface $mock) => $mock->shouldNotReceive('upsert'));

        try {
            app(ClassTeacherService::class)->assign($section, ['academic_year_id' => 3, 'staff_id' => 5]);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('staff_id', $e->errors());
        }
    }

    public function test_assign_rejects_a_non_teacher(): void
    {
        $section = new Section;
        $section->id = 1;
        $section->shift_id = 2;
        $staff = new Staff(['status' => Staff::STATUS_ACTIVE, 'category' => Staff::CATEGORY_STAFF]);
        $staff->id = 5;

        $this->mock(StaffRepositoryInterface::class, function (MockInterface $mock) use ($staff) {
            $mock->shouldReceive('findOrFail')->once()->with(5)->andReturn($staff);
        });
        $this->mock(ClassTeacherRepositoryInterface::class, fn (MockInterface $mock) => $mock->shouldNotReceive('upsert'));

        try {
            app(ClassTeacherService::class)->assign($section, ['academic_year_id' => 3, 'staff_id' => 5]);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('staff_id', $e->errors());
        }
    }

    public function test_assign_rejects_a_staff_member_outside_the_sections_shift(): void
    {
        $section = new Section;
        $section->id = 1;
        $section->shift_id = 2;
        $staff = new Staff(['status' => Staff::STATUS_ACTIVE, 'category' => Staff::CATEGORY_TEACHER]);
        $staff->id = 5;

        $this->mock(StaffRepositoryInterface::class, function (MockInterface $mock) use ($staff) {
            $mock->shouldReceive('findOrFail')->once()->with(5)->andReturn($staff);
            $mock->shouldReceive('belongsToShift')->once()->with($staff, 2)->andReturn(false);
        });
        $this->mock(ClassTeacherRepositoryInterface::class, fn (MockInterface $mock) => $mock->shouldNotReceive('upsert'));

        try {
            app(ClassTeacherService::class)->assign($section, ['academic_year_id' => 3, 'staff_id' => 5]);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('staff_id', $e->errors());
        }
    }

    public function test_assign_rejects_a_teacher_already_leading_another_section_this_year(): void
    {
        $section = new Section;
        $section->id = 1;
        $section->shift_id = 2;
        $staff = new Staff(['status' => Staff::STATUS_ACTIVE, 'category' => Staff::CATEGORY_TEACHER]);
        $staff->id = 5;

        $this->mock(StaffRepositoryInterface::class, function (MockInterface $mock) use ($staff) {
            $mock->shouldReceive('findOrFail')->once()->with(5)->andReturn($staff);
            $mock->shouldReceive('belongsToShift')->once()->with($staff, 2)->andReturn(true);
        });
        $this->mock(ClassTeacherRepositoryInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('teacherLeadsAnotherSection')->once()->with(3, 5, 1)->andReturn(true);
            $mock->shouldNotReceive('upsert');
        });

        try {
            app(ClassTeacherService::class)->assign($section, ['academic_year_id' => 3, 'staff_id' => 5]);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('staff_id', $e->errors());
        }
    }

    public function test_assign_succeeds_for_an_eligible_teacher(): void
    {
        $section = new Section;
        $section->id = 1;
        $section->shift_id = 2;
        $staff = new Staff(['status' => Staff::STATUS_ACTIVE, 'category' => Staff::CATEGORY_TEACHER]);
        $staff->id = 5;
        $classSection = new ClassSection;
        $classSection->staff = $staff;

        $this->mock(StaffRepositoryInterface::class, function (MockInterface $mock) use ($staff) {
            $mock->shouldReceive('findOrFail')->once()->with(5)->andReturn($staff);
            $mock->shouldReceive('belongsToShift')->once()->with($staff, 2)->andReturn(true);
        });
        $this->mock(ClassTeacherRepositoryInterface::class, function (MockInterface $mock) use ($section, $classSection) {
            $mock->shouldReceive('teacherLeadsAnotherSection')->once()->with(3, 5, 1)->andReturn(false);
            $mock->shouldReceive('upsert')->once()->with($section, 3, 5)->andReturn($classSection);
        });

        $result = app(ClassTeacherService::class)->assign($section, ['academic_year_id' => 3, 'staff_id' => 5]);

        $this->assertSame($staff, $result->staff);
    }

    public function test_assign_reports_a_concurrent_duplicate_assignment_as_a_validation_error(): void
    {
        $section = new Section;
        $section->id = 1;
        $section->shift_id = 2;
        $staff = new Staff(['status' => Staff::STATUS_ACTIVE, 'category' => Staff::CATEGORY_TEACHER]);
        $staff->id = 5;

        $this->mock(StaffRepositoryInterface::class, function (MockInterface $mock) use ($staff) {
            $mock->shouldReceive('findOrFail')->once()->with(5)->andReturn($staff);
            $mock->shouldReceive('belongsToShift')->once()->with($staff, 2)->andReturn(true);
        });
        $this->mock(ClassTeacherRepositoryInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('teacherLeadsAnotherSection')->once()->andReturn(false);
            $mock->shouldReceive('upsert')->once()->andThrow(
                new UniqueConstraintViolationException('sqlite', 'insert into class_sections', [], new RuntimeException('UNIQUE constraint failed'))
            );
        });

        try {
            app(ClassTeacherService::class)->assign($section, ['academic_year_id' => 3, 'staff_id' => 5]);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('staff_id', $e->errors());
        }
    }
}
