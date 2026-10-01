<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubjectAssignment\IndexSubjectAssignmentRequest;
use App\Http\Requests\SubjectAssignment\StoreSubjectAssignmentRequest;
use App\Http\Requests\SubjectAssignment\UpdateSubjectAssignmentRequest;
use App\Http\Resources\SubjectAssignmentResource;
use App\Models\SubjectAssignment;
use App\Services\SubjectAssignmentService;
use Illuminate\Routing\Controllers\HasMiddleware;

class SubjectAssignmentController extends Controller implements HasMiddleware
{
    public function __construct(private SubjectAssignmentService $assignments) {}

    public static function middleware(): array
    {
        // Subject teachers are part of a class's setup and share the *-classes permissions.
        return static::resourcePermissions('classes');
    }

    public function index(IndexSubjectAssignmentRequest $request)
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        return SubjectAssignmentResource::collection(
            $this->assignments->list(
                $request->safe()->only(['academic_year_id', 'class_id', 'section_id', 'staff_id', 'subject_id']),
                $perPage
            )
        );
    }

    public function store(StoreSubjectAssignmentRequest $request)
    {
        $assignment = $this->assignments->create($request->validated());

        return (new SubjectAssignmentResource($assignment))
            ->additional(['message' => 'Subject teacher assigned successfully'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(SubjectAssignment $subjectAssignment)
    {
        return new SubjectAssignmentResource($this->assignments->find($subjectAssignment));
    }

    public function update(UpdateSubjectAssignmentRequest $request, SubjectAssignment $subjectAssignment)
    {
        $assignment = $this->assignments->update($subjectAssignment, $request->validated());

        return (new SubjectAssignmentResource($assignment))->additional(['message' => 'Subject teacher updated successfully']);
    }

    public function destroy(SubjectAssignment $subjectAssignment)
    {
        $this->assignments->delete($subjectAssignment);

        return response()->noContent();
    }
}
