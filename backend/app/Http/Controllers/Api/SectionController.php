<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Section;
use Illuminate\Http\Request;

class SectionController extends Controller
{
    public function index(Request $request)
    {
        $query = Section::with('class');

        if ($request->has('class_id')) {
            $query->where('class_id', $request->class_id);
        }

        return $query->paginate($request->per_page ?? 15);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'class_id' => 'required|exists:classes,id',
            'name' => 'required|string|max:255',
            'code' => 'required|string',
            'capacity' => 'nullable|integer',
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        $section = Section::create($validated);

        return response()->json([
            'message' => 'Section created successfully',
            'data' => $section->load('class'),
        ], 201);
    }

    public function show(Section $section)
    {
        return response()->json($section->load('class'));
    }

    public function update(Request $request, Section $section)
    {
        $validated = $request->validate([
            'class_id' => 'sometimes|exists:classes,id',
            'name' => 'sometimes|string|max:255',
            'code' => 'sometimes|string',
            'capacity' => 'nullable|integer',
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        $section->update($validated);

        return response()->json([
            'message' => 'Section updated successfully',
            'data' => $section->load('class'),
        ]);
    }

    public function destroy(Section $section)
    {
        $section->delete();
        return response()->json(['message' => 'Section deleted successfully']);
    }
}

