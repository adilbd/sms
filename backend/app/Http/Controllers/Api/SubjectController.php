<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Subject\IndexSubjectRequest;
use App\Http\Requests\Subject\StoreSubjectRequest;
use App\Http\Requests\Subject\UpdateSubjectRequest;
use App\Http\Resources\SubjectResource;
use App\Models\Subject;
use App\Services\SubjectService;
use Illuminate\Routing\Controllers\HasMiddleware;

/**
 * Reference implementation of the Controller → Service → Repository pattern.
 * See docs/architecture-guidelines.md.
 */
class SubjectController extends Controller implements HasMiddleware
{
    public function __construct(private SubjectService $subjects) {}

    public static function middleware(): array
    {
        return static::resourcePermissions('subjects');
    }

    public function index(IndexSubjectRequest $request)
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        return SubjectResource::collection(
            $this->subjects->list($request->safe()->only(['search', 'is_active']), $perPage)
        );
    }

    public function store(StoreSubjectRequest $request)
    {
        $subject = $this->subjects->create($request->validated());

        return (new SubjectResource($subject))
            ->additional(['message' => 'Subject created successfully'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Subject $subject)
    {
        return new SubjectResource($subject);
    }

    public function update(UpdateSubjectRequest $request, Subject $subject)
    {
        $subject = $this->subjects->update($subject, $request->validated());

        return (new SubjectResource($subject))->additional(['message' => 'Subject updated successfully']);
    }

    public function destroy(Subject $subject)
    {
        $this->subjects->delete($subject);

        return response()->noContent();
    }
}
