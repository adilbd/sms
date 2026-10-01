<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FeeReport\CollectionReportRequest;
use App\Http\Requests\FeeReport\DuesReportRequest;
use App\Http\Requests\FeeReport\LedgerRequest;
use App\Http\Resources\FeeReportResource;
use App\Models\Student;
use App\Services\FeeReportService;
use Illuminate\Routing\Controllers\HasMiddleware;

class FeeReportController extends Controller implements HasMiddleware
{
    public function __construct(private FeeReportService $reports) {}

    public static function middleware(): array
    {
        return static::resourcePermissions('fees', [
            'dues' => 'view-fees',
            'collection' => 'view-fees',
            'ledger' => 'view-fees',
        ]);
    }

    public function dues(DuesReportRequest $request)
    {
        $data = $request->validated();

        return new FeeReportResource($this->reports->dues(
            (int) $data['section_id'],
            isset($data['academic_year_id']) ? (int) $data['academic_year_id'] : null,
            $data['month'] ?? null,
        ));
    }

    public function collection(CollectionReportRequest $request)
    {
        $data = $request->validated();

        return new FeeReportResource($this->reports->collection(
            $data['from'],
            $data['to'],
            ['method' => $data['method'] ?? null, 'collected_by' => $data['collected_by'] ?? null],
        ));
    }

    public function ledger(LedgerRequest $request, Student $student)
    {
        $yearId = $request->validated('academic_year_id');

        return new FeeReportResource($this->reports->ledger($student, filled($yearId) ? (int) $yearId : null));
    }
}
