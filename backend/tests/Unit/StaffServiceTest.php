<?php

namespace Tests\Unit;

use App\Models\Staff;
use App\Repositories\Contracts\ShiftRepositoryInterface;
use App\Repositories\Contracts\StaffRepositoryInterface;
use App\Services\StaffService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Services are unit-tested against a mocked repository interface. A real (empty)
 * database is still used because StaffService reloads relations (shifts/educations/
 * trainings) after a successful write.
 */
class StaffServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_rejects_a_second_active_head_in_the_same_shift(): void
    {
        $this->mock(StaffRepositoryInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('hasActiveInPosition')->once()->with(1, Staff::POSITION_HEAD, null)->andReturn(true);
            $mock->shouldNotReceive('create');
        });

        $data = [
            'name_en' => 'New Head',
            'category' => Staff::CATEGORY_TEACHER,
            'position' => Staff::POSITION_HEAD,
            'status' => Staff::STATUS_ACTIVE,
            'shift_ids' => [1],
        ];

        try {
            app(StaffService::class)->create($data, null);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('position', $e->errors());
        }
    }

    public function test_create_allows_an_active_head_in_a_different_shift(): void
    {
        $this->mock(StaffRepositoryInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('hasActiveInPosition')->once()->with(2, Staff::POSITION_HEAD, null)->andReturn(false);
            $mock->shouldReceive('create')->once()->andReturn(new Staff);
            $mock->shouldReceive('syncShifts')->once();
            $mock->shouldReceive('syncEducations')->once();
            $mock->shouldReceive('syncTrainings')->once();
        });

        $data = [
            'name_en' => 'New Head',
            'category' => Staff::CATEGORY_TEACHER,
            'position' => Staff::POSITION_HEAD,
            'status' => Staff::STATUS_ACTIVE,
            'shift_ids' => [2],
        ];

        app(StaffService::class)->create($data, null);
    }

    public function test_create_requires_leaving_date_for_a_former_status(): void
    {
        $this->mock(StaffRepositoryInterface::class, fn (MockInterface $mock) => $mock->shouldNotReceive('create'));

        $data = [
            'name_en' => 'Old Teacher',
            'category' => Staff::CATEGORY_TEACHER,
            'position' => Staff::POSITION_TEACHER,
            'status' => Staff::STATUS_RETIRED,
            'shift_ids' => [1],
        ];

        try {
            app(StaffService::class)->create($data, null);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('leaving_date', $e->errors());
        }
    }

    public function test_create_requires_at_least_one_name(): void
    {
        $this->mock(StaffRepositoryInterface::class, fn (MockInterface $mock) => $mock->shouldNotReceive('create'));

        $data = [
            'category' => Staff::CATEGORY_TEACHER,
            'position' => Staff::POSITION_TEACHER,
            'status' => Staff::STATUS_ACTIVE,
            'shift_ids' => [1],
        ];

        try {
            app(StaffService::class)->create($data, null);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('name_en', $e->errors());
        }
    }

    public function test_create_deletes_the_uploaded_photo_when_the_transaction_fails(): void
    {
        Storage::fake('public');
        $photo = UploadedFile::fake()->image('photo.jpg');

        $this->mock(StaffRepositoryInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('hasActiveInPosition')->andReturn(false);
            $mock->shouldReceive('create')->once()->andThrow(new \RuntimeException('boom'));
        });

        $data = [
            'name_en' => 'New Teacher',
            'category' => Staff::CATEGORY_TEACHER,
            'position' => Staff::POSITION_TEACHER,
            'status' => Staff::STATUS_ACTIVE,
            'shift_ids' => [1],
        ];

        try {
            app(StaffService::class)->create($data, $photo);
            $this->fail('Expected the underlying exception to propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        Storage::disk('public')->assertDirectoryEmpty('staff');
    }

    public function test_update_deletes_the_old_photo_after_a_successful_replace(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('staff/old.jpg', 'old-contents');
        $newPhoto = UploadedFile::fake()->image('new.jpg');

        $staff = Staff::factory()->make(['photo' => 'staff/old.jpg']);
        $staff->id = 5;
        $staff->exists = true;
        $staff->syncOriginal();

        $this->mock(ShiftRepositoryInterface::class);
        $this->mock(StaffRepositoryInterface::class, function (MockInterface $mock) use ($staff) {
            $mock->shouldReceive('hasActiveInPosition')->andReturn(false);
            $mock->shouldReceive('update')->once()->andReturnUsing(function ($model, $data) use ($staff) {
                $staff->fill($data);

                return $staff;
            });
            $mock->shouldReceive('syncShifts')->once();
        });

        app(StaffService::class)->update($staff, ['shift_ids' => [1]], $newPhoto, false);

        Storage::disk('public')->assertMissing('staff/old.jpg');
    }

    /**
     * Regression test: the head/assistant_head-per-shift check used to run before the
     * transaction (and before locking the shift rows), so two concurrent requests could
     * both read "no active head yet" and both succeed. The shift must now be locked
     * before the check, and the check before the write.
     */
    public function test_create_locks_the_shift_before_checking_for_an_existing_head(): void
    {
        $this->mock(ShiftRepositoryInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('lockForUpdate')->once()->with([3])->globally()->ordered();
        });

        $this->mock(StaffRepositoryInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('hasActiveInPosition')->once()->with(3, Staff::POSITION_HEAD, null)->andReturn(false)->globally()->ordered();
            $mock->shouldReceive('create')->once()->andReturn(new Staff)->globally()->ordered();
            $mock->shouldReceive('syncShifts')->once();
            $mock->shouldReceive('syncEducations')->once();
            $mock->shouldReceive('syncTrainings')->once();
        });

        app(StaffService::class)->create([
            'name_en' => 'New Head',
            'category' => Staff::CATEGORY_TEACHER,
            'position' => Staff::POSITION_HEAD,
            'status' => Staff::STATUS_ACTIVE,
            'shift_ids' => [3],
        ], null);
    }

    /**
     * Regression test: update() used to read a staff member's current shifts with
     * `$staff->shifts()->pluck(...)` directly in the service instead of through the
     * repository.
     */
    public function test_update_reads_current_shifts_through_the_repository_when_none_are_sent(): void
    {
        $staff = Staff::factory()->make(['position' => Staff::POSITION_TEACHER]);
        $staff->id = 9;
        $staff->exists = true;
        $staff->syncOriginal();

        // No shift_ids sent and the position isn't head/assistant_head, so nothing on
        // the Shift repository should be called at all (not even lockForUpdate()).
        $this->mock(ShiftRepositoryInterface::class);
        $this->mock(StaffRepositoryInterface::class, function (MockInterface $mock) use ($staff) {
            $mock->shouldReceive('shiftIdsFor')->once()->with($staff)->andReturn([7]);
            $mock->shouldReceive('update')->once()->andReturn($staff);
        });

        app(StaffService::class)->update($staff, ['bio' => 'Updated bio'], null, false);
    }

    /**
     * Regression test: delete() used to soft-delete a staff member without checking
     * subject_assignments.staff_id, even though foreign keys don't protect soft deletes.
     */
    public function test_delete_is_refused_when_staff_has_subject_assignments(): void
    {
        $staff = new Staff;

        $this->mock(StaffRepositoryInterface::class, function (MockInterface $mock) use ($staff) {
            $mock->shouldReceive('hasSubjectAssignments')->once()->with($staff)->andReturn(true);
            $mock->shouldNotReceive('delete');
        });

        try {
            app(StaffService::class)->delete($staff);
            $this->fail('Expected a 409 HttpException.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    public function test_create_rejects_a_staff_position_with_teacher_category(): void
    {
        $this->mock(StaffRepositoryInterface::class, fn (MockInterface $mock) => $mock->shouldNotReceive('create'));

        $data = [
            'name_en' => 'Office Assistant',
            'category' => Staff::CATEGORY_TEACHER,
            'position' => Staff::POSITION_STAFF,
            'status' => Staff::STATUS_ACTIVE,
            'shift_ids' => [1],
        ];

        try {
            app(StaffService::class)->create($data, null);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('category', $e->errors());
        }
    }

    public function test_update_rejects_a_teaching_position_with_staff_category(): void
    {
        $staff = Staff::factory()->make(['category' => Staff::CATEGORY_STAFF, 'position' => Staff::POSITION_STAFF]);
        $staff->id = 12;
        $staff->exists = true;
        $staff->syncOriginal();

        $this->mock(ShiftRepositoryInterface::class);
        $this->mock(StaffRepositoryInterface::class, fn (MockInterface $mock) => $mock->shouldNotReceive('update'));

        try {
            app(StaffService::class)->update($staff, ['position' => Staff::POSITION_TEACHER], null, false);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('category', $e->errors());
        }
    }

    public function test_delete_removes_a_staff_member_with_no_subject_assignments(): void
    {
        $staff = new Staff;

        $this->mock(StaffRepositoryInterface::class, function (MockInterface $mock) use ($staff) {
            $mock->shouldReceive('hasSubjectAssignments')->once()->with($staff)->andReturn(false);
            $mock->shouldReceive('delete')->once()->with($staff);
        });

        app(StaffService::class)->delete($staff);
    }
}
