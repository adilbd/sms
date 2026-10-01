<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FeeHead\IndexFeeHeadRequest;
use App\Http\Requests\FeeHead\StoreFeeHeadRequest;
use App\Http\Requests\FeeHead\UpdateFeeHeadRequest;
use App\Http\Resources\FeeHeadResource;
use App\Models\FeeHead;
use App\Services\FeeHeadService;
use Illuminate\Routing\Controllers\HasMiddleware;

class FeeHeadController extends Controller implements HasMiddleware
{
    public function __construct(private FeeHeadService $heads) {}

    public static function middleware(): array
    {
        return static::resourcePermissions('fees');
    }

    public function index(IndexFeeHeadRequest $request)
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        return FeeHeadResource::collection(
            $this->heads->list($request->safe()->only(['search', 'kind', 'is_active']), $perPage)
        );
    }

    public function store(StoreFeeHeadRequest $request)
    {
        return (new FeeHeadResource($this->heads->create($request->validated())))
            ->additional(['message' => 'Fee head created successfully'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(FeeHead $feeHead)
    {
        return new FeeHeadResource($feeHead);
    }

    public function update(UpdateFeeHeadRequest $request, FeeHead $feeHead)
    {
        return (new FeeHeadResource($this->heads->update($feeHead, $request->validated())))
            ->additional(['message' => 'Fee head updated successfully']);
    }

    public function destroy(FeeHead $feeHead)
    {
        $this->heads->delete($feeHead);

        return response()->noContent();
    }
}
