<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Exam\IndexExamMarkRequest;
use App\Http\Requests\Exam\SaveExamMarksRequest;
use App\Http\Resources\ExamMarkSheetResource;
use App\Models\Exam;
use App\Services\ExamMarkService;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Mark entry sheets. Both actions need `enter-results`; the assigned-teacher-or-admin rule
 * is in ExamMarkService.
 */
class ExamMarkController extends Controller implements HasMiddleware
{
    public function __construct(private ExamMarkService $marks) {}

    public static function middleware(): array
    {
        return [new Middleware('permission:enter-results')];
    }

    public function sheet(IndexExamMarkRequest $request, Exam $exam)
    {
        $data = $request->validated();

        return new ExamMarkSheetResource(
            $this->marks->sheet($request->user(), $exam, (int) $data['section_id'], (int) $data['exam_subject_id'])
        );
    }

    public function save(SaveExamMarksRequest $request, Exam $exam)
    {
        return (new ExamMarkSheetResource($this->marks->save($request->user(), $exam, $request->validated())))
            ->additional(['message' => 'Marks saved successfully']);
    }
}
