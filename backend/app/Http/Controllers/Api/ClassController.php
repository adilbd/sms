<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Class\IndexClassRequest;
use App\Http\Requests\Class\IndexCurriculumRequest;
use App\Http\Requests\Class\StoreClassRequest;
use App\Http\Requests\Class\SyncCurriculumRequest;
use App\Http\Requests\Class\UpdateClassRequest;
use App\Http\Resources\ClassResource;
use App\Http\Resources\ClassSubjectResource;
use App\Models\Classes;
use App\Services\ClassService;
use App\Services\CurriculumService;
use Illuminate\Routing\Controllers\HasMiddleware;

class ClassController extends Controller implements HasMiddleware
{
    public function __construct(
        private ClassService $classes,
        private CurriculumService $curriculum,
    ) {}

    public static function middleware(): array
    {
        return static::resourcePermissions('classes', [
            'curriculum' => 'view-classes',
            'updateCurriculum' => 'edit-classes',
        ]);
    }

    public function index(IndexClassRequest $request)
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        return ClassResource::collection(
            $this->classes->list($request->safe()->only(['search', 'is_active', 'level']), $perPage)
        );
    }

    public function store(StoreClassRequest $request)
    {
        $class = $this->classes->create($request->validated());

        return (new ClassResource($class))
            ->additional(['message' => 'Class created successfully'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Classes $class)
    {
        return new ClassResource($this->classes->find($class));
    }

    public function update(UpdateClassRequest $request, Classes $class)
    {
        $class = $this->classes->update($class, $request->validated());

        return (new ClassResource($class))->additional(['message' => 'Class updated successfully']);
    }

    public function destroy(Classes $class)
    {
        $this->classes->delete($class);

        return response()->noContent();
    }

    public function curriculum(IndexCurriculumRequest $request, Classes $class)
    {
        return ClassSubjectResource::collection(
            $this->curriculum->get($class, $request->validated('group'))
        );
    }

    public function updateCurriculum(SyncCurriculumRequest $request, Classes $class)
    {
        return ClassSubjectResource::collection($this->curriculum->sync($class, $request->validated('subjects')))
            ->additional(['message' => 'Curriculum updated successfully']);
    }
}
