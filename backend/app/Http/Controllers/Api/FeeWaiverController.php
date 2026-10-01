<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FeeWaiver\IndexFeeWaiverRequest;
use App\Http\Requests\FeeWaiver\StoreFeeWaiverRequest;
use App\Http\Requests\FeeWaiver\UpdateFeeWaiverRequest;
use App\Http\Resources\FeeWaiverResource;
use App\Models\StudentFeeWaiver;
use App\Services\FeeWaiverService;
use Illuminate\Routing\Controllers\HasMiddleware;

class FeeWaiverController extends Controller implements HasMiddleware
{
    public function __construct(private FeeWaiverService $waivers) {}

    public static function middleware(): array
    {
        // Reads need view-fees; every write needs create-fees (the office role holds it).
        return static::resourcePermissions('fees', [
            'update' => 'create-fees',
            'destroy' => 'create-fees',
        ]);
    }

    public function index(IndexFeeWaiverRequest $request)
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        return FeeWaiverResource::collection(
            $this->waivers->list($request->safe()->only(['student_id', 'academic_year_id', 'fee_head_id']), $perPage)
        );
    }

    public function store(StoreFeeWaiverRequest $request)
    {
        $waiver = $this->waivers->create([...$request->validated(), 'approved_by' => $request->user()->id]);

        return (new FeeWaiverResource($waiver))
            ->additional(['message' => 'Fee waiver created successfully'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(StudentFeeWaiver $feeWaiver)
    {
        return new FeeWaiverResource($this->waivers->find($feeWaiver));
    }

    public function update(UpdateFeeWaiverRequest $request, StudentFeeWaiver $feeWaiver)
    {
        return (new FeeWaiverResource($this->waivers->update($feeWaiver, $request->validated())))
            ->additional(['message' => 'Fee waiver updated successfully']);
    }

    public function destroy(StudentFeeWaiver $feeWaiver)
    {
        $this->waivers->delete($feeWaiver);

        return response()->noContent();
    }
}
