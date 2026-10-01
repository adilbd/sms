<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MyRecords\IndexMyAssignmentsRequest;
use App\Http\Resources\ExamResultResource;
use App\Http\Resources\MyAssignmentsResource;
use App\Http\Resources\MyExamScheduleResource;
use App\Http\Resources\StudentResource;
use App\Services\ResultService;
use App\Services\StudentService;
use App\Services\TeacherScope;
use Illuminate\Http\Request;

/**
 * Scoped own-record reads for the student and guardian roles. Routes are guarded by
 * `role:` middleware in routes/api.php; there is no broad view-students permission for
 * these roles (it would list every student).
 */
class MyRecordsController extends Controller
{
    public function __construct(
        private StudentService $students,
        private ResultService $results,
        private TeacherScope $teacherScope,
    ) {}

    public function student(Request $request)
    {
        // The caller's own (or own child's) record, so sensitive fields are included.
        return (new StudentResource($this->students->findOwn($request->user())))->withSensitive();
    }

    public function children(Request $request)
    {
        return StudentResource::collectionFor($this->students->childrenOf($request->user()), sensitive: true);
    }

    /**
     * The signed-in teacher's subjects and class-teacher sections for the active (or
     * given) academic year. A teacher with no linked staff row gets an empty block.
     */
    public function assignments(IndexMyAssignmentsRequest $request)
    {
        $yearId = $request->validated('academic_year_id');

        return new MyAssignmentsResource(
            $this->teacherScope->forUser($request->user(), filled($yearId) ? (int) $yearId : null)
        );
    }

    public function results(Request $request)
    {
        return ExamResultResource::collectionWithSubjects($this->results->ownResults($request->user()));
    }

    public function childResults(Request $request, int $student)
    {
        return ExamResultResource::collectionWithSubjects($this->results->childResults($request->user(), $student));
    }

    public function exams(Request $request)
    {
        return MyExamScheduleResource::collection($this->results->ownSchedule($request->user()));
    }
}
