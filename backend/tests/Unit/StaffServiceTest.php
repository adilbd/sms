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
}
