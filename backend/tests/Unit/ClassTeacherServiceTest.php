<?php

namespace Tests\Unit;

use App\Models\ClassSection;
use App\Models\Section;
use App\Models\Staff;
use App\Repositories\Contracts\ClassTeacherRepositoryInterface;
use App\Repositories\Contracts\StaffRepositoryInterface;
use App\Services\ClassTeacherService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * Services are unit-tested against a mocked repository interface, no database.
 */
class ClassTeacherServiceTest extends TestCase
{
    private function section(): Section
    {
        $section = new Section;
        $section->id = 1;
        $section->shift_id = 2;

        return $section;
    }

    private function staff(int $id, string $status = Staff::STATUS_ACTIVE, string $category = Staff::CATEGORY_TEACHER): Staff
    {
        $staff = new Staff(['status' => $status, 'category' => $category]);
        $staff->id = $id;

        return $staff;
    }

    /**
     * @param  array<int, Staff>  $staff
     */
    private function mockStaff(array $staff, bool $inShift = true): void
    {
        $this->mock(StaffRepositoryInterface::class, function (MockInterface $mock) use ($staff, $inShift) {
            foreach ($staff as $id => $member) {
                $mock->shouldReceive('findOrFail')->with($id)->andReturn($member);
            }
            $mock->shouldReceive('belongsToShift')->andReturn($inShift)->byDefault();
        });
    }

    private function mockRepo(?callable $extra = null): void
    {
        $this->mock(ClassTeacherRepositoryInterface::class, function (MockInterface $mock) use ($extra) {
            $mock->shouldReceive('lockSection')->andReturnUsing(fn (Section $s) => $s)->byDefault();
            if ($extra) {
                $extra($mock);
            }
        });
    }

    public function test_replace_saves_one_main_and_co_teachers(): void
    {
        $section = $this->section();
        $this->mockStaff([5 => $this->staff(5), 6 => $this->staff(6), 7 => $this->staff(7)]);
        $this->mockRepo(function (MockInterface $mock) use ($section) {
            $mock->shouldReceive('replaceForSectionAndYear')->once()->with($section, 3, [
                ['staff_id' => 5, 'is_main' => true],
                ['staff_id' => 6, 'is_main' => false],
                ['staff_id' => 7, 'is_main' => false],
            ]);
            $mock->shouldReceive('forSectionAndYear')->once()->andReturn(new Collection([new ClassSection]));
        });

        $result = app(ClassTeacherService::class)->replace($section, ['academic_year_id' => 3, 'teachers' => [
            ['staff_id' => 5, 'is_main' => true],
            ['staff_id' => 6, 'is_main' => false],
            ['staff_id' => 7, 'is_main' => false],
        ]]);

        $this->assertCount(1, $result);
    }

    public function test_replace_with_an_empty_list_removes_every_teacher(): void
    {
        $section = $this->section();
        $this->mockStaff([]);
        $this->mockRepo(function (MockInterface $mock) use ($section) {
            $mock->shouldReceive('replaceForSectionAndYear')->once()->with($section, 3, []);
            $mock->shouldReceive('forSectionAndYear')->once()->andReturn(new Collection);
        });

        $this->assertCount(0, app(ClassTeacherService::class)->replace($section, ['academic_year_id' => 3, 'teachers' => []]));
    }

    public function test_replace_requires_exactly_one_main_teacher(): void
    {
        $this->mockStaff([5 => $this->staff(5), 6 => $this->staff(6)]);
        $this->mockRepo(fn (MockInterface $mock) => $mock->shouldNotReceive('replaceForSectionAndYear'));

        try {
            app(ClassTeacherService::class)->replace($this->section(), ['academic_year_id' => 3, 'teachers' => [
                ['staff_id' => 5, 'is_main' => false], ['staff_id' => 6, 'is_main' => false],
            ]]);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('teachers', $e->errors());
        }

        try {
            app(ClassTeacherService::class)->replace($this->section(), ['academic_year_id' => 3, 'teachers' => [
                ['staff_id' => 5, 'is_main' => true], ['staff_id' => 6, 'is_main' => true],
            ]]);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('teachers.1.is_main', $e->errors());
        }
    }

    public function test_replace_reports_an_ineligible_teacher_on_their_row(): void
    {
        $this->mockStaff([5 => $this->staff(5), 6 => $this->staff(6, Staff::STATUS_RETIRED), 7 => $this->staff(7, category: Staff::CATEGORY_STAFF)]);
        $this->mockRepo(fn (MockInterface $mock) => $mock->shouldNotReceive('replaceForSectionAndYear'));

        try {
            app(ClassTeacherService::class)->replace($this->section(), ['academic_year_id' => 3, 'teachers' => [
                ['staff_id' => 5, 'is_main' => true], ['staff_id' => 6, 'is_main' => false], ['staff_id' => 7, 'is_main' => false],
            ]]);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame(['teachers.1.staff_id', 'teachers.2.staff_id'], array_keys($e->errors()));
        }
    }

    public function test_replace_rejects_a_staff_member_outside_the_sections_shift(): void
    {
        $staff = $this->staff(5);
        $this->mockStaff([5 => $staff], inShift: false);
        $this->mockRepo(fn (MockInterface $mock) => $mock->shouldNotReceive('replaceForSectionAndYear'));

        try {
            app(ClassTeacherService::class)->replace($this->section(), ['academic_year_id' => 3, 'teachers' => [['staff_id' => 5, 'is_main' => true]]]);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('teachers.0.staff_id', $e->errors());
        }
    }

    public function test_replace_reports_a_concurrent_duplicate_as_a_validation_error(): void
    {
        $this->mockStaff([5 => $this->staff(5)]);
        $this->mockRepo(function (MockInterface $mock) {
            $mock->shouldReceive('replaceForSectionAndYear')->once()->andThrow(
                new UniqueConstraintViolationException('sqlite', 'insert into class_sections', [], new RuntimeException('UNIQUE constraint failed'))
            );
        });

        try {
            app(ClassTeacherService::class)->replace($this->section(), ['academic_year_id' => 3, 'teachers' => [['staff_id' => 5, 'is_main' => true]]]);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('teachers', $e->errors());
        }
    }
}
