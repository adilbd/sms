<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Exam\IndexExamSubjectRequest;
use App\Http\Requests\Exam\UpdateExamSubjectRequest;
use App\Http\Resources\ExamSubjectResource;
use App\Models\Exam;
use App\Models\ExamSubject;
use App\Services\ExamScheduleService;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * An exam's subject schedule. Reads and edits use the exam permissions.
 */
class ExamSubjectController extends Controller implements HasMiddleware
{
    public function __construct(private ExamScheduleService $schedule) {}

    public static function middleware(): array
    {
        return [
            new Middleware('permission:view-exams', only: ['index']),
            new Middleware('permission:edit-exams', only: ['update']),
        ];
    }

    public function index(IndexExamSubjectRequest $request, Exam $exam)
    {
        $classId = $request->safe()->only(['class_id'])['class_id'] ?? null;

        return ExamSubjectResource::collection($this->schedule->list($exam, filled($classId) ? (int) $classId : null));
    }

    public function update(UpdateExamSubjectRequest $request, Exam $exam, ExamSubject $examSubject)
    {
        return (new ExamSubjectResource($this->schedule->update($exam, $examSubject, $request->validated())))
            ->additional(['message' => 'Subject schedule updated successfully']);
    }
}
