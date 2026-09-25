<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;

class AcademicYearController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        // Any signed-in user can read academic years; changing them is a settings task.
        return static::resourcePermissions('settings', [
            'index' => null,
            'show' => null,
            'store' => 'edit-settings',
            'update' => 'edit-settings',
            'destroy' => 'edit-settings',
            'activate' => 'edit-settings',
        ]);
    }

    public function index(Request $request)
    {
        return AcademicYear::orderBy('start_date', 'desc')
                          ->paginate($request->per_page ?? 15);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|unique:academic_years,code',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'description' => 'nullable|string',
        ]);

        $academicYear = AcademicYear::create($validated);

        return response()->json([
            'message' => 'Academic year created successfully',
            'data' => $academicYear,
        ], 201);
    }

    public function show(AcademicYear $academicYear)
    {
        return response()->json($academicYear);
    }

    public function update(Request $request, AcademicYear $academicYear)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'code' => 'sometimes|string|unique:academic_years,code,' . $academicYear->id,
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date|after:start_date',
            'description' => 'nullable|string',
        ]);

        $academicYear->update($validated);

        return response()->json([
            'message' => 'Academic year updated successfully',
            'data' => $academicYear,
        ]);
    }

    public function destroy(AcademicYear $academicYear)
    {
        $academicYear->delete();
        return response()->json(['message' => 'Academic year deleted successfully']);
    }

    public function activate(AcademicYear $academicYear)
    {
        AcademicYear::where('is_active', true)->update(['is_active' => false]);
        $academicYear->update(['is_active' => true]);

        return response()->json([
            'message' => 'Academic year activated successfully',
            'data' => $academicYear,
        ]);
    }
}

