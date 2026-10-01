<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Section\AssignClassTeacherRequest;
use App\Http\Requests\Section\IndexSectionRequest;
use App\Http\Requests\Section\StoreSectionRequest;
use App\Http\Requests\Section\UpdateSectionRequest;
use App\Http\Requests\SubjectAssignment\SyncSectionSubjectTeachersRequest;
use App\Http\Resources\ClassTeacherResource;
use App\Http\Resources\SectionResource;
use App\Http\Resources\SubjectAssignmentResource;
use App\Models\Section;
use App\Services\ClassTeacherService;
use App\Services\SectionService;
use App\Services\SubjectAssignmentService;
use Illuminate\Routing\Controllers\HasMiddleware;

class SectionController extends Controller implements HasMiddleware
{
    public function __construct(
        private SectionService $sections,
        private ClassTeacherService $classTeachers,
        private SubjectAssignmentService $subjectAssignments,
    ) {}

    public static function middleware(): array
    {
        // Sections belong to classes and share their permissions.
        return static::resourcePermissions('classes', [
            'classTeachers' => 'view-classes',
            'updateClassTeacher' => 'edit-classes',
            'updateSubjectTeachers' => 'edit-classes',
        ]);
    }

    public function index(IndexSectionRequest $request)
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        return SectionResource::collection(
            $this->sections->list($request->safe()->only(['search', 'class_id', 'shift_id', 'group', 'is_active']), $perPage)
        );
    }

    public function store(StoreSectionRequest $request)
    {
        $section = $this->sections->create($request->validated());

        return (new SectionResource($section))
            ->additional(['message' => 'Section created successfully'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Section $section)
    {
        return new SectionResource($this->sections->find($section));
    }

    public function update(UpdateSectionRequest $request, Section $section)
    {
        $section = $this->sections->update($section, $request->validated());

        return (new SectionResource($section))->additional(['message' => 'Section updated successfully']);
    }

    public function destroy(Section $section)
    {
        $this->sections->delete($section);

        return response()->noContent();
    }

    public function classTeachers(Section $section)
    {
        return ClassTeacherResource::collection($this->classTeachers->listForSection($section));
    }

    public function updateClassTeacher(AssignClassTeacherRequest $request, Section $section)
    {
        $result = $this->classTeachers->assign($section, $request->validated());

        $message = $result->staff ? 'Class teacher assigned successfully' : 'Class teacher removed successfully';

        return (new ClassTeacherResource($result))->additional(['message' => $message]);
    }

    public function updateSubjectTeachers(SyncSectionSubjectTeachersRequest $request, Section $section)
    {
        return SubjectAssignmentResource::collection(
            $this->subjectAssignments->syncForSection($section, $request->validated())
        )->additional(['message' => 'Subject teachers updated successfully']);
    }
}
