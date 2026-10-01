<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Exam\IndexExamRequest;
use App\Http\Requests\Exam\StoreExamRequest;
use App\Http\Requests\Exam\UpdateExamRequest;
use App\Http\Resources\ExamResource;
use App\Models\Classes;
use App\Models\Exam;
use App\Services\ExamService;
use Illuminate\Routing\Controllers\HasMiddleware;

class ExamController extends Controller implements HasMiddleware
{
    public function __construct(private ExamService $exams) {}

    public static function middleware(): array
    {
        return static::resourcePermissions('exams', [
            'openMarksEntry' => 'edit-exams',
            'regenerateClass' => 'edit-exams',
        ]);
    }

    public function index(IndexExamRequest $request)
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        return ExamResource::collection(
            $this->exams->list($request->safe()->only(['academic_year_id', 'type', 'status', 'search']), $perPage)
        );
    }

    public function store(StoreExamRequest $request)
    {
        $exam = $this->exams->create($request->validated());

        return (new ExamResource($exam))
            ->additional(['message' => 'Exam created successfully'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Exam $exam)
    {
        return new ExamResource($this->exams->find($exam));
    }

    public function update(UpdateExamRequest $request, Exam $exam)
    {
        $exam = $this->exams->update($exam, $request->validated());

        return (new ExamResource($exam))->additional(['message' => 'Exam updated successfully']);
    }

    public function destroy(Exam $exam)
    {
        $this->exams->delete($exam);

        return response()->noContent();
    }

    public function openMarksEntry(Exam $exam)
    {
        return (new ExamResource($this->exams->openMarksEntry($exam)))
            ->additional(['message' => 'Mark entry opened']);
    }

    public function regenerateClass(Exam $exam, Classes $class)
    {
        return (new ExamResource($this->exams->regenerateClass($exam, $class)))
            ->additional(['message' => 'Class schedule regenerated from the curriculum']);
    }
}
