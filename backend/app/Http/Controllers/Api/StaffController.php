<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\IndexStaffRequest;
use App\Http\Requests\Staff\StoreStaffRequest;
use App\Http\Requests\Staff\UpdateStaffRequest;
use App\Http\Resources\StaffResource;
use App\Models\Staff;
use App\Services\StaffService;
use Illuminate\Routing\Controllers\HasMiddleware;

/**
 * Replaces the old TeacherController stub. Reuses the *-teachers permissions seeded
 * by RolePermissionSeeder (see docs/tasks/staff-module.md).
 */
class StaffController extends Controller implements HasMiddleware
{
    public function __construct(private StaffService $staff) {}

    public static function middleware(): array
    {
        return static::resourcePermissions('teachers');
    }

    public function index(IndexStaffRequest $request)
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        return StaffResource::collection(
            $this->staff->list($request->safe()->only(['search', 'category', 'position', 'is_active', 'shift']), $perPage)
        );
    }

    public function store(StoreStaffRequest $request)
    {
        $staff = $this->staff->create($request->safe()->except(['photo']), $request->file('photo'));

        return (new StaffResource($staff))
            ->additional(['message' => 'Staff member created successfully'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Staff $staff)
    {
        return new StaffResource($this->staff->find($staff));
    }

    public function update(UpdateStaffRequest $request, Staff $staff)
    {
        $staff = $this->staff->update(
            $staff,
            $request->safe()->except(['photo', 'remove_photo']),
            $request->file('photo'),
            $request->boolean('remove_photo'),
        );

        return (new StaffResource($staff))->additional(['message' => 'Staff member updated successfully']);
    }

    public function destroy(Staff $staff)
    {
        $this->staff->delete($staff);

        return response()->noContent();
    }
}
