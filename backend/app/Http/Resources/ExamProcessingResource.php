<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What processing an exam returns: the exam (now `processed`) and the summary per class and
 * section. Wraps the array ResultService::process() returns.
 *
 * @property array{exam: \App\Models\Exam, summary: list<array<string, mixed>>} $resource
 */
class ExamProcessingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'exam' => new ExamResource($this->resource['exam']),
            'summary' => $this->resource['summary'],
        ];
    }
}
