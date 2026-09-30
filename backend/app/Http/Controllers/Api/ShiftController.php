<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shift\IndexShiftRequest;
use App\Http\Requests\Shift\StoreShiftRequest;
use App\Http\Requests\Shift\UpdateShiftRequest;
use App\Http\Resources\ShiftResource;
use App\Models\Shift;
use App\Services\ShiftService;
use Illuminate\Routing\Controllers\HasMiddleware;

class ShiftController extends Controller implements HasMiddleware
{
    public function __construct(private ShiftService $shifts) {}

    public static function middleware(): array
    {
        // Any signed-in user can read shifts (used to populate the staff form and the
        // public filter pills); changing them is a settings task, like academic years.
        return static::resourcePermissions('settings', [
            'index' => null,
            'show' => null,
            'store' => 'edit-settings',
            'update' => 'edit-settings',
            'destroy' => 'edit-settings',
        ]);
    }

    public function index(IndexShiftRequest $request)
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        return ShiftResource::collection(
            $this->shifts->list($request->safe()->only(['search', 'is_active']), $perPage)
        );
    }

    public function store(StoreShiftRequest $request)
    {
        $shift = $this->shifts->create($request->validated());

        return (new ShiftResource($shift))
            ->additional(['message' => 'Shift created successfully'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Shift $shift)
    {
        return new ShiftResource($shift);
    }

    public function update(UpdateShiftRequest $request, Shift $shift)
    {
        $shift = $this->shifts->update($shift, $request->validated());

        return (new ShiftResource($shift))->additional(['message' => 'Shift updated successfully']);
    }

    public function destroy(Shift $shift)
    {
        $this->shifts->delete($shift);

        return response()->noContent();
    }
}
