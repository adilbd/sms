<?php

namespace Tests\Unit;

use App\Models\Classes;
use App\Models\Section;
use App\Repositories\Contracts\ClassRepositoryInterface;
use App\Repositories\Contracts\ClassTeacherRepositoryInterface;
use App\Repositories\Contracts\SectionRepositoryInterface;
use App\Services\SectionService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Services are unit-tested against a mocked repository interface. A real (empty)
 * database is still used because SectionService reloads relations (class/shift) after
 * a successful write (see StaffServiceTest for the same pattern).
 */
class SectionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_rejects_a_group_below_class_9(): void
    {
        $class = new Classes(['number' => 8]);

        $this->mock(ClassRepositoryInterface::class, function (MockInterface $mock) use ($class) {
            $mock->shouldReceive('findOrFail')->once()->with(1)->andReturn($class);
        });
        $this->mock(SectionRepositoryInterface::class, fn (MockInterface $mock) => $mock->shouldNotReceive('create'));
        $this->mock(ClassTeacherRepositoryInterface::class);

        try {
            app(SectionService::class)->create(['class_id' => 1, 'shift_id' => 1, 'name' => 'A', 'code' => 'A', 'group' => 'science']);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('group', $e->errors());
        }
    }

    public function test_create_allows_a_group_for_class_9_and_above(): void
    {
        $class = new Classes(['number' => 9]);
        $section = new Section;

        $this->mock(ClassRepositoryInterface::class, function (MockInterface $mock) use ($class) {
            $mock->shouldReceive('findOrFail')->once()->with(1)->andReturn($class);
        });
        $this->mock(SectionRepositoryInterface::class, function (MockInterface $mock) use ($section) {
            $mock->shouldReceive('create')->once()->andReturn($section);
        });
        $this->mock(ClassTeacherRepositoryInterface::class);

        app(SectionService::class)->create(['class_id' => 1, 'shift_id' => 1, 'name' => 'A', 'code' => 'A', 'group' => 'science']);
    }

    public function test_update_rejects_adding_a_group_to_a_class_5_section(): void
    {
        $class = new Classes(['number' => 5]);
        $section = Section::factory()->make(['class_id' => 1, 'group' => null]);
        $section->id = 10;
        $section->exists = true;

        $this->mock(ClassRepositoryInterface::class, function (MockInterface $mock) use ($class) {
            $mock->shouldReceive('findOrFail')->once()->with(1)->andReturn($class);
        });
        $this->mock(SectionRepositoryInterface::class, fn (MockInterface $mock) => $mock->shouldNotReceive('update'));
        $this->mock(ClassTeacherRepositoryInterface::class);

        try {
            app(SectionService::class)->update($section, ['group' => 'humanities']);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('group', $e->errors());
        }
    }

    public function test_delete_is_refused_when_section_has_students(): void
    {
        $section = new Section;

        $this->mock(SectionRepositoryInterface::class, function (MockInterface $mock) use ($section) {
            $mock->shouldReceive('hasStudents')->once()->with($section)->andReturn(true);
            $mock->shouldNotReceive('delete');
        });
        $this->mock(ClassTeacherRepositoryInterface::class, fn (MockInterface $mock) => $mock->shouldNotReceive('deleteForSection'));

        $this->assertConflict(fn () => app(SectionService::class)->delete($section));
    }

    public function test_delete_is_refused_when_section_has_attendances(): void
    {
        $section = new Section;

        $this->mock(SectionRepositoryInterface::class, function (MockInterface $mock) use ($section) {
            $mock->shouldReceive('hasStudents')->once()->andReturn(false);
            $mock->shouldReceive('hasAttendances')->once()->with($section)->andReturn(true);
            $mock->shouldNotReceive('delete');
        });
        $this->mock(ClassTeacherRepositoryInterface::class, fn (MockInterface $mock) => $mock->shouldNotReceive('deleteForSection'));

        $this->assertConflict(fn () => app(SectionService::class)->delete($section));
    }

    public function test_delete_is_refused_when_section_has_subject_assignments(): void
    {
        $section = new Section;

        $this->mock(SectionRepositoryInterface::class, function (MockInterface $mock) use ($section) {
            $mock->shouldReceive('hasStudents')->once()->andReturn(false);
            $mock->shouldReceive('hasAttendances')->once()->andReturn(false);
            $mock->shouldReceive('hasSubjectAssignments')->once()->with($section)->andReturn(true);
            $mock->shouldNotReceive('delete');
        });
        $this->mock(ClassTeacherRepositoryInterface::class, fn (MockInterface $mock) => $mock->shouldNotReceive('deleteForSection'));

        $this->assertConflict(fn () => app(SectionService::class)->delete($section));
    }

    public function test_delete_removes_class_teacher_rows_before_the_section(): void
    {
        $section = new Section;

        // Declared in call order: Mockery's globally()->ordered() enforces the order
        // expectations are *declared* in, not the order their mocks are constructed.
        $this->mock(ClassTeacherRepositoryInterface::class, function (MockInterface $mock) use ($section) {
            $mock->shouldReceive('deleteForSection')->once()->with($section)->globally()->ordered();
        });
        $this->mock(SectionRepositoryInterface::class, function (MockInterface $mock) use ($section) {
            $mock->shouldReceive('hasStudents')->once()->andReturn(false);
            $mock->shouldReceive('hasAttendances')->once()->andReturn(false);
            $mock->shouldReceive('hasSubjectAssignments')->once()->andReturn(false);
            $mock->shouldReceive('hasRoutineSlots')->once()->andReturn(false);
            $mock->shouldReceive('delete')->once()->with($section)->globally()->ordered();
        });

        app(SectionService::class)->delete($section);
    }

    public function test_create_reports_a_concurrent_duplicate_code_as_a_validation_error(): void
    {
        $class = new Classes(['number' => 1]);

        $this->mock(ClassRepositoryInterface::class, function (MockInterface $mock) use ($class) {
            $mock->shouldReceive('findOrFail')->once()->andReturn($class);
        });
        $this->mock(SectionRepositoryInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('create')->once()->andThrow(
                new UniqueConstraintViolationException('sqlite', 'insert into sections', [], new RuntimeException('UNIQUE constraint failed'))
            );
        });
        $this->mock(ClassTeacherRepositoryInterface::class);

        try {
            app(SectionService::class)->create(['class_id' => 1, 'shift_id' => 1, 'name' => 'A', 'code' => 'A']);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('code', $e->errors());
        }
    }

    private function assertConflict(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected a 409 HttpException.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    public function test_delete_is_refused_when_section_has_a_class_routine(): void
    {
        $section = new Section;

        $this->mock(SectionRepositoryInterface::class, function (MockInterface $mock) use ($section) {
            $mock->shouldReceive('hasStudents', 'hasAttendances', 'hasSubjectAssignments')->andReturn(false);
            $mock->shouldReceive('hasRoutineSlots')->once()->with($section)->andReturn(true);
            $mock->shouldNotReceive('delete');
        });
        $this->mock(ClassTeacherRepositoryInterface::class, fn (MockInterface $mock) => $mock->shouldNotReceive('deleteForSection'));

        $this->assertConflict(fn () => app(SectionService::class)->delete($section));
    }
}
