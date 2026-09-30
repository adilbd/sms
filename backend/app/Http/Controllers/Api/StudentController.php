<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Student\IndexStudentRequest;
use App\Http\Requests\Student\StoreStudentRequest;
use App\Http\Requests\Student\UpdateStudentRequest;
use App\Http\Resources\StudentEnrolmentResource;
use App\Http\Resources\StudentResource;
use App\Models\Student;
use App\Services\StudentService;
use Illuminate\Routing\Controllers\HasMiddleware;

class StudentController extends Controller implements HasMiddleware
{
    public function __construct(private StudentService $students) {}

    public static function middleware(): array
    {
        return static::resourcePermissions('students', [
            'enrolments' => 'view-students',
        ]);
    }

    public function index(IndexStudentRequest $request)
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        return StudentResource::collection(
            $this->students->list(
                $request->safe()->only(['academic_year_id', 'class_id', 'section_id', 'shift_id', 'group', 'status', 'search']),
                $perPage,
            )
        );
    }

    public function store(StoreStudentRequest $request)
    {
        $student = $this->students->create($request->safe()->except(['photo']), $request->file('photo'));

        return (new StudentResource($student))
            ->additional(['message' => 'Student created successfully'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Student $student)
    {
        return new StudentResource($this->students->find($student));
    }

    public function update(UpdateStudentRequest $request, Student $student)
    {
        $student = $this->students->update(
            $student,
            $request->safe()->except(['photo', 'remove_photo']),
            $request->file('photo'),
            $request->boolean('remove_photo'),
        );

        return (new StudentResource($student))->additional(['message' => 'Student updated successfully']);
    }

    public function destroy(Student $student)
    {
        $this->students->delete($student);

        return response()->noContent();
    }

    public function enrolments(Student $student)
    {
        return StudentEnrolmentResource::collection($this->students->enrolmentHistory($student));
    }
}
