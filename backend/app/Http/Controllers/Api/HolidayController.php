<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Holiday\IndexHolidayRequest;
use App\Http\Requests\Holiday\StoreHolidayRequest;
use App\Http\Requests\Holiday\UpdateHolidayRequest;
use App\Http\Resources\HolidayResource;
use App\Models\Holiday;
use App\Services\HolidayService;
use Illuminate\Routing\Controllers\HasMiddleware;

class HolidayController extends Controller implements HasMiddleware
{
    public function __construct(private HolidayService $holidays) {}

    public static function middleware(): array
    {
        // Reads need view-attendance (teachers see the calendar they mark against);
        // changing the calendar is a settings task, like academic years and shifts.
        return static::resourcePermissions('attendance', [
            'store' => 'edit-settings',
            'update' => 'edit-settings',
            'destroy' => 'edit-settings',
        ]);
    }

    public function index(IndexHolidayRequest $request)
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        return HolidayResource::collection(
            $this->holidays->list($request->safe()->only(['academic_year_id']), $perPage)
        );
    }

    public function store(StoreHolidayRequest $request)
    {
        return (new HolidayResource($this->holidays->create($request->validated())))
            ->additional(['message' => 'Holiday created successfully'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Holiday $holiday)
    {
        return new HolidayResource($holiday);
    }

    public function update(UpdateHolidayRequest $request, Holiday $holiday)
    {
        return (new HolidayResource($this->holidays->update($holiday, $request->validated())))
            ->additional(['message' => 'Holiday updated successfully']);
    }

    public function destroy(Holiday $holiday)
    {
        $this->holidays->delete($holiday);

        return response()->noContent();
    }
}
