<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Period\IndexPeriodRequest;
use App\Http\Requests\Period\StorePeriodRequest;
use App\Http\Requests\Period\UpdatePeriodRequest;
use App\Http\Resources\PeriodResource;
use App\Models\Period;
use App\Services\PeriodService;
use Illuminate\Routing\Controllers\HasMiddleware;

class PeriodController extends Controller implements HasMiddleware
{
    public function __construct(private PeriodService $periods) {}

    public static function middleware(): array
    {
        // Any signed-in user can read periods (like shifts); changing them is a settings task.
        return static::resourcePermissions('settings', [
            'index' => null,
            'show' => null,
            'store' => 'edit-settings',
            'update' => 'edit-settings',
            'destroy' => 'edit-settings',
        ]);
    }

    public function index(IndexPeriodRequest $request)
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        return PeriodResource::collection(
            $this->periods->list($request->safe()->only(['shift_id']), $perPage)
        );
    }

    public function store(StorePeriodRequest $request)
    {
        $period = $this->periods->create($request->validated());

        return (new PeriodResource($period))
            ->additional(['message' => 'Period created successfully'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Period $period)
    {
        return new PeriodResource($period);
    }

    public function update(UpdatePeriodRequest $request, Period $period)
    {
        $period = $this->periods->update($period, $request->validated());

        return (new PeriodResource($period))->additional(['message' => 'Period updated successfully']);
    }

    public function destroy(Period $period)
    {
        $this->periods->delete($period);

        return response()->noContent();
    }
}
