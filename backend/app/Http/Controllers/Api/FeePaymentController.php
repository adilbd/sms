<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FeePayment\CancelFeePaymentRequest;
use App\Http\Requests\FeePayment\IndexFeePaymentRequest;
use App\Http\Requests\FeePayment\StoreFeePaymentRequest;
use App\Http\Resources\FeePaymentResource;
use App\Models\FeePayment;
use App\Services\FeePaymentService;
use Illuminate\Routing\Controllers\HasMiddleware;

class FeePaymentController extends Controller implements HasMiddleware
{
    public function __construct(private FeePaymentService $payments) {}

    public static function middleware(): array
    {
        // Collecting is its own permission; cancelling needs delete-fees, which only the
        // admin role holds.
        return static::resourcePermissions('fees', [
            'store' => 'collect-fees',
            'cancel' => 'delete-fees',
        ]);
    }

    public function index(IndexFeePaymentRequest $request)
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        return FeePaymentResource::collection($this->payments->list(
            $request->safe()->only(['student_id', 'method', 'collected_by', 'from', 'to', 'status', 'search']),
            $perPage,
        ));
    }

    public function store(StoreFeePaymentRequest $request)
    {
        return (new FeePaymentResource($this->payments->collect($request->validated(), $request->user())))
            ->additional(['message' => 'Payment recorded successfully'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(FeePayment $feePayment)
    {
        return new FeePaymentResource($this->payments->find($feePayment));
    }

    public function cancel(CancelFeePaymentRequest $request, FeePayment $feePayment)
    {
        return (new FeePaymentResource($this->payments->cancel($feePayment, $request->validated('reason'), $request->user())))
            ->additional(['message' => 'Payment cancelled successfully']);
    }
}
