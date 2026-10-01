<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FeeRate\IndexFeeRateRequest;
use App\Http\Requests\FeeRate\StoreFeeRateRequest;
use App\Http\Requests\FeeRate\UpdateFeeRateRequest;
use App\Http\Resources\FeeRateResource;
use App\Models\FeeRate;
use App\Services\FeeRateService;
use Illuminate\Routing\Controllers\HasMiddleware;

class FeeRateController extends Controller implements HasMiddleware
{
    public function __construct(private FeeRateService $rates) {}

    public static function middleware(): array
    {
        return static::resourcePermissions('fees');
    }

    public function index(IndexFeeRateRequest $request)
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        return FeeRateResource::collection(
            $this->rates->list($request->safe()->only(['academic_year_id', 'class_id', 'fee_head_id']), $perPage)
        );
    }

    public function store(StoreFeeRateRequest $request)
    {
        return (new FeeRateResource($this->rates->create($request->validated())))
            ->additional(['message' => 'Fee rate created successfully'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(FeeRate $feeRate)
    {
        return new FeeRateResource($this->rates->find($feeRate));
    }

    public function update(UpdateFeeRateRequest $request, FeeRate $feeRate)
    {
        return (new FeeRateResource($this->rates->update($feeRate, $request->validated())))
            ->additional(['message' => 'Fee rate updated successfully']);
    }

    public function destroy(FeeRate $feeRate)
    {
        $this->rates->delete($feeRate);

        return response()->noContent();
    }
}
