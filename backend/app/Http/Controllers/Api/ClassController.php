<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Classes;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;

class ClassController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return static::resourcePermissions('classes');
    }

    public function index(Request $request)
    {
        $query = Classes::with('sections');

        if ($request->has('search')) {
            $query->where('name', 'like', "%{$request->search}%")
                  ->orWhere('code', 'like', "%{$request->search}%");
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->is_active);
        }

        return $query->orderBy('display_order')
                    ->paginate($request->per_page ?? 15);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|unique:classes,code',
            'description' => 'nullable|string',
            'display_order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);

        $class = Classes::create($validated);

        return response()->json([
            'message' => 'Class created successfully',
            'data' => $class,
        ], 201);
    }

    public function show(Classes $class)
    {
        return response()->json($class->load('sections'));
    }

    public function update(Request $request, Classes $class)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'code' => 'sometimes|string|unique:classes,code,' . $class->id,
            'description' => 'nullable|string',
            'display_order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);

        $class->update($validated);

        return response()->json([
            'message' => 'Class updated successfully',
            'data' => $class,
        ]);
    }

    public function destroy(Classes $class)
    {
        try {
            $class->delete();
            return response()->json(['message' => 'Class deleted successfully']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to delete class', 'error' => $e->getMessage()], 500);
        }
    }
}

