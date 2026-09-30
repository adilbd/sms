<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AcademicYear\IndexAcademicYearRequest;
use App\Http\Requests\AcademicYear\StoreAcademicYearRequest;
use App\Http\Requests\AcademicYear\UpdateAcademicYearRequest;
use App\Http\Resources\AcademicYearResource;
use App\Models\AcademicYear;
use App\Services\AcademicYearService;
use Illuminate\Routing\Controllers\HasMiddleware;

class AcademicYearController extends Controller implements HasMiddleware
{
    public function __construct(private AcademicYearService $academicYears) {}

    public static function middleware(): array
    {
        // Any signed-in user can read academic years; changing them is a settings task.
        return static::resourcePermissions('settings', [
            'index' => null,
            'show' => null,
            'store' => 'edit-settings',
            'update' => 'edit-settings',
            'destroy' => 'edit-settings',
            'activate' => 'edit-settings',
        ]);
    }

    public function index(IndexAcademicYearRequest $request)
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        return AcademicYearResource::collection(
            $this->academicYears->list($request->safe()->only(['is_active']), $perPage)
        );
    }

    public function store(StoreAcademicYearRequest $request)
    {
        $academicYear = $this->academicYears->create($request->validated());

        return (new AcademicYearResource($academicYear))
            ->additional(['message' => 'Academic year created successfully'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(AcademicYear $academicYear)
    {
        return new AcademicYearResource($academicYear);
    }

    public function update(UpdateAcademicYearRequest $request, AcademicYear $academicYear)
    {
        $academicYear = $this->academicYears->update($academicYear, $request->validated());

        return (new AcademicYearResource($academicYear))->additional(['message' => 'Academic year updated successfully']);
    }

    public function destroy(AcademicYear $academicYear)
    {
        $this->academicYears->delete($academicYear);

        return response()->noContent();
    }

    public function activate(AcademicYear $academicYear)
    {
        $academicYear = $this->academicYears->activate($academicYear);

        return (new AcademicYearResource($academicYear))->additional(['message' => 'Academic year activated successfully']);
    }
}
