<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FeeDue\GenerateFeeDuesRequest;
use App\Http\Requests\FeeDue\IndexFeeDueRequest;
use App\Http\Resources\FeeDueResource;
use App\Http\Resources\FeeReportResource;
use App\Services\FeeDueService;
use Illuminate\Routing\Controllers\HasMiddleware;

class FeeDueController extends Controller implements HasMiddleware
{
    public function __construct(private FeeDueService $dues) {}

    public static function middleware(): array
    {
        return static::resourcePermissions('fees', ['generate' => 'create-fees']);
    }

    public function index(IndexFeeDueRequest $request)
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        return FeeDueResource::collection($this->dues->list(
            $request->safe()->only(['student_id', 'section_id', 'class_id', 'academic_year_id', 'fee_head_id', 'month', 'status']),
            $perPage,
        ));
    }

    /**
     * Creates the missing dues, or with `dry_run` only counts them.
     */
    public function generate(GenerateFeeDuesRequest $request)
    {
        $dryRun = $request->boolean('dry_run');

        return (new FeeReportResource($this->dues->generate($request->safe()->except('dry_run'), $dryRun)))
            ->additional(['message' => $dryRun ? 'Preview of the dues to generate' : 'Fee dues generated successfully']);
    }
}
