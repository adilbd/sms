<?php

namespace Tests\Unit;

use App\Models\Staff;
use App\Models\User;
use App\Repositories\Contracts\ShiftRepositoryInterface;
use App\Repositories\Contracts\StaffRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
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
            $mock->shouldReceive('isClassTeacher')->once()->with($staff)->andReturn(false);
            $mock->shouldReceive('isInRoutine')->once()->with($staff)->andReturn(false);
            $mock->shouldReceive('hasHomework')->once()->with($staff)->andReturn(false);
            $mock->shouldReceive('delete')->once()->with($staff);
        });

        app(StaffService::class)->delete($staff);
    }

    /**
     * Regression test: delete() used to soft-delete a staff member without checking
     * class_sections.staff_id, even though foreign keys don't protect soft deletes.
     */
    public function test_delete_is_refused_when_staff_is_a_class_teacher(): void
    {
        $staff = new Staff;

        $this->mock(StaffRepositoryInterface::class, function (MockInterface $mock) use ($staff) {
            $mock->shouldReceive('hasSubjectAssignments')->once()->with($staff)->andReturn(false);
            $mock->shouldReceive('isClassTeacher')->once()->with($staff)->andReturn(true);
            $mock->shouldNotReceive('delete');
        });

        try {
            app(StaffService::class)->delete($staff);
            $this->fail('Expected a 409 HttpException.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    // --- logins -------------------------------------------------------------------------

    private function loginData(array $login = [], array $override = []): array
    {
        return $override + [
            'name_en' => 'Md. Karim',
            'employee_id' => 'VHBUB-12',
            'category' => Staff::CATEGORY_TEACHER,
            'position' => Staff::POSITION_TEACHER,
            'status' => Staff::STATUS_ACTIVE,
            'shift_ids' => [1],
            'login' => $login + ['enabled' => true, 'role' => 'teacher', 'password' => 'secret-pass'],
        ];
    }

    private function savedStaff(array $attributes = []): Staff
    {
        $staff = Staff::factory()->make($attributes + ['employee_id' => 'VHBUB-12']);
        $staff->id = 5;
        $staff->exists = true;
        $staff->syncOriginal();

        return $staff;
    }

    private function user(int $id, array $attributes = []): User
    {
        $user = new User($attributes + ['username' => 'vhbub-12']);
        $user->id = $id;
        $user->exists = true;
        $user->syncOriginal();

        return $user;
    }

    private function assertLoginRejected(array $data, string $field): void
    {
        try {
            app(StaffService::class)->create($data, null);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
    }

    public function test_create_with_a_login_requires_an_employee_id(): void
    {
        $this->mock(StaffRepositoryInterface::class, fn (MockInterface $mock) => $mock->shouldNotReceive('create'));
        $this->mock(UserRepositoryInterface::class, fn (MockInterface $mock) => $mock->shouldNotReceive('createWithRole'));

        $this->assertLoginRejected($this->loginData([], ['employee_id' => null]), 'employee_id');
        $this->assertLoginRejected($this->loginData([], ['employee_id' => '  ']), 'employee_id');
    }

    public function test_create_with_a_login_requires_the_role_to_match_the_category(): void
    {
        $this->mock(StaffRepositoryInterface::class, fn (MockInterface $mock) => $mock->shouldNotReceive('create'));
        $this->mock(UserRepositoryInterface::class, fn (MockInterface $mock) => $mock->shouldNotReceive('createWithRole'));

        $this->assertLoginRejected($this->loginData(['role' => 'office']), 'login.role');
        $this->assertLoginRejected($this->loginData(['role' => 'teacher'], ['category' => 'staff', 'position' => 'staff']), 'login.role');
    }

    public function test_create_with_a_login_rejects_a_taken_username_or_email_and_a_missing_password(): void
    {
        $this->mock(StaffRepositoryInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('create')->andReturn($this->savedStaff());
            $mock->shouldReceive('syncShifts');
            $mock->shouldReceive('syncEducations');
            $mock->shouldReceive('syncTrainings');
        });

        $this->mock(UserRepositoryInterface::class, function (MockInterface $mock) {
            // vhbub-12 belongs to somebody else; nobody owns vhbub-13.
            $mock->shouldReceive('findForLogin')->with('vhbub-12')->andReturn($this->user(99));
            $mock->shouldReceive('findForLogin')->with('vhbub-13')->andReturn(null);
            $mock->shouldReceive('findForLogin')->with('taken@example.com')->andReturn($this->user(98));
            $mock->shouldNotReceive('createWithRole');
        });

        $this->assertLoginRejected($this->loginData(), 'employee_id');

        $this->mock(StaffRepositoryInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('create')->andReturn($this->savedStaff(['employee_id' => 'VHBUB-13']));
            $mock->shouldReceive('syncShifts');
            $mock->shouldReceive('syncEducations');
            $mock->shouldReceive('syncTrainings');
        });
        $this->assertLoginRejected($this->loginData(['email' => 'taken@example.com'], ['employee_id' => 'VHBUB-13']), 'login.email');
        $this->assertLoginRejected($this->loginData(['password' => null], ['employee_id' => 'VHBUB-13']), 'login.password');
    }

    public function test_create_with_a_login_creates_the_user_with_the_role_and_links_it(): void
    {
        $user = $this->user(77);

        $this->mock(StaffRepositoryInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('create')->once()->andReturn($this->savedStaff(['login_enabled' => true]));
            $mock->shouldReceive('syncShifts');
            $mock->shouldReceive('syncEducations');
            $mock->shouldReceive('syncTrainings');
            $mock->shouldReceive('update')->once()->withArgs(fn ($staff, $data) => $data === ['user_id' => 77])->andReturn($this->savedStaff(['user_id' => 77]));
        });
        $this->mock(UserRepositoryInterface::class, function (MockInterface $mock) use ($user) {
            $mock->shouldReceive('findForLogin')->with('vhbub-12')->andReturn(null);
            $mock->shouldReceive('findForLogin')->with('karim@example.com')->andReturn(null);
            $mock->shouldReceive('createWithRole')->once()->withArgs(fn (array $attributes, string $role) => $role === 'teacher'
                && $attributes['username'] === 'vhbub-12'
                && $attributes['email'] === 'karim@example.com'
                && $attributes['password'] === 'secret-pass'
                && $attributes['is_active'] === true)->andReturn($user);
        });

        app(StaffService::class)->create($this->loginData(['email' => 'Karim@Example.com']), null);
    }

    public function test_update_disabling_the_login_deactivates_the_user_and_revokes_tokens(): void
    {
        $staff = $this->savedStaff(['user_id' => 77]);
        $user = $this->user(77);

        $this->mock(ShiftRepositoryInterface::class);
        $this->mock(StaffRepositoryInterface::class, function (MockInterface $mock) use ($staff) {
            $mock->shouldReceive('shiftIdsFor')->andReturn([1]);
            $mock->shouldReceive('update')->andReturn($staff);
        });
        $this->mock(UserRepositoryInterface::class, function (MockInterface $mock) use ($user) {
            $mock->shouldReceive('find')->with(77)->andReturn($user);
            $mock->shouldReceive('roleNames')->andReturn(['teacher']);
            $mock->shouldReceive('update')->once()->with($user, ['is_active' => false]);
            $mock->shouldReceive('revokeAllTokens')->once()->with($user);
        });

        app(StaffService::class)->update($staff, ['login' => ['enabled' => false]], null, false);
    }

    public function test_update_to_a_former_status_deactivates_the_login_and_returning_reactivates_it(): void
    {
        $staff = $this->savedStaff(['user_id' => 77, 'login_enabled' => true]);
        $user = $this->user(77);
        $captured = [];

        $this->mock(ShiftRepositoryInterface::class);
        $this->mock(StaffRepositoryInterface::class, function (MockInterface $mock) use ($staff) {
            $mock->shouldReceive('shiftIdsFor')->andReturn([1]);
            $mock->shouldReceive('update')->andReturnUsing(function ($model, $data) use ($staff) {
                $staff->fill($data);

                return $staff;
            });
        });
        $this->mock(UserRepositoryInterface::class, function (MockInterface $mock) use ($user, &$captured) {
            $mock->shouldReceive('find')->with(77)->andReturn($user);
            $mock->shouldReceive('roleNames')->andReturn(['teacher']);
            $mock->shouldReceive('findForLogin')->andReturn(null);
            $mock->shouldReceive('update')->andReturnUsing(function ($model, $attributes) use (&$captured) {
                $captured[] = $attributes;

                return $model;
            });
            $mock->shouldReceive('revokeAllTokens')->once()->with($user);
        });

        app(StaffService::class)->update($staff, ['status' => Staff::STATUS_RETIRED, 'leaving_date' => '2026-06-30'], null, false);
        $this->assertFalse($captured[0]['is_active']);

        // Back to active: reactivated, and no token revocation this time (once() above).
        $staff->syncOriginal();
        $captured = [];
        app(StaffService::class)->update($staff, ['status' => Staff::STATUS_ACTIVE], null, false);
        $this->assertTrue($captured[0]['is_active']);
    }

    public function test_update_refuses_to_use_a_student_or_parent_account_as_a_login(): void
    {
        $staff = $this->savedStaff(['user_id' => 77]);
        $user = $this->user(77);

        $this->mock(ShiftRepositoryInterface::class);
        $this->mock(StaffRepositoryInterface::class, function (MockInterface $mock) use ($staff) {
            $mock->shouldReceive('shiftIdsFor')->andReturn([1]);
            $mock->shouldReceive('update')->andReturn($staff);
        });
        $this->mock(UserRepositoryInterface::class, function (MockInterface $mock) use ($user) {
            $mock->shouldReceive('find')->with(77)->andReturn($user);
            $mock->shouldReceive('roleNames')->andReturn(['student']);
            $mock->shouldNotReceive('update');
            $mock->shouldNotReceive('syncRole');
        });

        try {
            app(StaffService::class)->update($staff, ['login' => ['enabled' => true, 'role' => 'teacher', 'password' => 'secret-pass']], null, false);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('login.enabled', $e->errors());
        }
    }

    public function test_update_changing_the_employee_id_changes_the_username_unless_taken(): void
    {
        $staff = $this->savedStaff(['user_id' => 77, 'login_enabled' => true]);
        $user = $this->user(77);
        $captured = [];

        $this->mock(ShiftRepositoryInterface::class);
        $this->mock(StaffRepositoryInterface::class, function (MockInterface $mock) use ($staff) {
            $mock->shouldReceive('shiftIdsFor')->andReturn([1]);
            $mock->shouldReceive('update')->andReturnUsing(function ($model, $data) use ($staff) {
                $staff->fill($data);

                return $staff;
            });
        });
        $this->mock(UserRepositoryInterface::class, function (MockInterface $mock) use ($user, &$captured) {
            $mock->shouldReceive('find')->with(77)->andReturn($user);
            $mock->shouldReceive('roleNames')->andReturn(['teacher']);
            $mock->shouldReceive('findForLogin')->with('vhbub-99')->andReturn(null);
            $mock->shouldReceive('findForLogin')->with('vhbub-55')->andReturn($this->user(11, ['username' => 'vhbub-55']));
            $mock->shouldReceive('update')->andReturnUsing(function ($model, $attributes) use (&$captured) {
                $captured[] = $attributes;

                return $model;
            });
        });

        app(StaffService::class)->update($staff, ['employee_id' => 'VHBUB-99'], null, false);
        $this->assertSame('vhbub-99', $captured[0]['username']);

        try {
            app(StaffService::class)->update($staff, ['employee_id' => 'VHBUB-55'], null, false);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('employee_id', $e->errors());
        }
    }

    public function test_delete_deactivates_the_login(): void
    {
        $staff = $this->savedStaff(['user_id' => 77]);
        $user = $this->user(77);

        $this->mock(StaffRepositoryInterface::class, function (MockInterface $mock) use ($staff) {
            $mock->shouldReceive('hasSubjectAssignments')->andReturn(false);
            $mock->shouldReceive('isClassTeacher')->andReturn(false);
            $mock->shouldReceive('isInRoutine')->andReturn(false);
            $mock->shouldReceive('hasHomework')->andReturn(false);
            $mock->shouldReceive('delete')->once()->with($staff);
        });
        $this->mock(UserRepositoryInterface::class, function (MockInterface $mock) use ($user) {
            $mock->shouldReceive('find')->with(77)->andReturn($user);
            $mock->shouldReceive('roleNames')->andReturn(['office']);
            $mock->shouldReceive('update')->once()->with($user, ['is_active' => false]);
            $mock->shouldReceive('revokeAllTokens')->once()->with($user);
        });

        app(StaffService::class)->delete($staff);
    }

    public function test_delete_leaves_a_non_staff_account_linked_to_the_member_alone(): void
    {
        $staff = $this->savedStaff(['user_id' => 77]);

        $this->mock(StaffRepositoryInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('hasSubjectAssignments')->andReturn(false);
            $mock->shouldReceive('isClassTeacher')->andReturn(false);
            $mock->shouldReceive('isInRoutine')->andReturn(false);
            $mock->shouldReceive('hasHomework')->andReturn(false);
            $mock->shouldReceive('delete')->once();
        });
        $this->mock(UserRepositoryInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('find')->with(77)->andReturn($this->user(77));
            $mock->shouldReceive('roleNames')->andReturn(['admin']);
            $mock->shouldNotReceive('update');
            $mock->shouldNotReceive('revokeAllTokens');
        });

        app(StaffService::class)->delete($staff);
    }

    public function test_delete_is_refused_when_staff_teaches_in_a_class_routine(): void
    {
        $staff = new Staff;

        $this->mock(StaffRepositoryInterface::class, function (MockInterface $mock) use ($staff) {
            $mock->shouldReceive('hasSubjectAssignments', 'isClassTeacher')->andReturn(false);
            $mock->shouldReceive('isInRoutine')->once()->with($staff)->andReturn(true);
            $mock->shouldNotReceive('delete');
        });

        try {
            app(StaffService::class)->delete($staff);
            $this->fail('Expected a 409 HttpException.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }
}
