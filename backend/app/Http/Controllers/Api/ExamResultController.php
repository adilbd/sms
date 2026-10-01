<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Exam\IndexExamResultRequest;
use App\Http\Resources\ExamProcessingResource;
use App\Http\Resources\ExamResource;
use App\Http\Resources\ExamResultResource;
use App\Models\Exam;
use App\Services\ResultService;
use Illuminate\Routing\Controllers\HasMiddleware;

/**
 * Processing, publishing and reading an exam's results. Reading needs `view-results`;
 * processing and publishing need `publish-exams`. A student's or guardian's own results are
 * in MyRecordsController.
 */
class ExamResultController extends Controller implements HasMiddleware
{
    public function __construct(private ResultService $results) {}

    public static function middleware(): array
    {
        return static::resourcePermissions('results', [
            'process' => 'publish-exams',
            'publish' => 'publish-exams',
            'unpublish' => 'publish-exams',
        ]);
    }

    public function index(IndexExamResultRequest $request, Exam $exam)
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        $results = $this->results->tabulation($exam, $request->safe()->only(['class_id', 'section_id']), $perPage);

        return $request->boolean('with_subjects')
            ? ExamResultResource::collectionWithSubjects($results)
            : ExamResultResource::collection($results);
    }

    public function show(Exam $exam, int $student)
    {
        return (new ExamResultResource($this->results->breakdown($exam, $student)))->withSubjects();
    }

    public function process(Exam $exam)
    {
        return (new ExamProcessingResource($this->results->process($exam)))
            ->additional(['message' => 'Results processed successfully']);
    }

    public function publish(Exam $exam)
    {
        return (new ExamResource($this->results->publish($exam)))->additional(['message' => 'Results published']);
    }

    public function unpublish(Exam $exam)
    {
        return (new ExamResource($this->results->unpublish($exam)))->additional(['message' => 'Results unpublished']);
    }
}
