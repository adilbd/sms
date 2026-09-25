<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class StudentController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return static::resourcePermissions('students');
    }

    public function index(Request $request)
    {
        $query = Student::with(['user', 'class', 'section', 'academicYear', 'parents']);

        if ($request->has('class_id')) {
            $query->where('class_id', $request->class_id);
        }

        if ($request->has('section_id')) {
            $query->where('section_id', $request->section_id);
        }

        if ($request->has('academic_year_id')) {
            $query->where('academic_year_id', $request->academic_year_id);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            })->orWhere('admission_number', 'like', "%{$search}%");
        }

        return $query->paginate($request->per_page ?? 15);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'phone' => 'nullable|string|max:20',
            'password' => 'required|string|min:8',
            'admission_number' => 'required|string|unique:students,admission_number',
            'roll_number' => 'nullable|string',
            'class_id' => 'required|exists:classes,id',
            'section_id' => 'required|exists:sections,id',
            'academic_year_id' => 'required|exists:academic_years,id',
            'admission_date' => 'required|date',
            'date_of_birth' => 'required|date',
            'gender' => 'required|in:male,female,other',
            'blood_group' => 'nullable|in:A+,A-,B+,B-,O+,O-,AB+,AB-',
            'address' => 'required|string',
            'city' => 'required|string',
            'state' => 'required|string',
            'country' => 'nullable|string',
            'pincode' => 'required|string',
            'photo' => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
                'password' => Hash::make($validated['password']),
                'is_active' => true,
            ]);

            $user->assignRole('student');

            $student = $user->student()->create([
                'admission_number' => $validated['admission_number'],
                'roll_number' => $validated['roll_number'] ?? null,
                'class_id' => $validated['class_id'],
                'section_id' => $validated['section_id'],
                'academic_year_id' => $validated['academic_year_id'],
                'admission_date' => $validated['admission_date'],
                'date_of_birth' => $validated['date_of_birth'],
                'gender' => $validated['gender'],
                'blood_group' => $validated['blood_group'] ?? null,
                'address' => $validated['address'],
                'city' => $validated['city'],
                'state' => $validated['state'],
                'country' => $validated['country'] ?? 'India',
                'pincode' => $validated['pincode'],
                'photo' => $validated['photo'] ?? null,
                'is_active' => true,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Student created successfully',
                'data' => $student->load(['user', 'class', 'section', 'academicYear']),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to create student', 'error' => $e->getMessage()], 500);
        }
    }

    public function show(Student $student)
    {
        return response()->json($student->load(['user', 'class', 'section', 'academicYear', 'parents']));
    }

    public function update(Request $request, Student $student)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $student->user_id,
            'phone' => 'nullable|string|max:20',
            'roll_number' => 'nullable|string',
            'class_id' => 'sometimes|exists:classes,id',
            'section_id' => 'sometimes|exists:sections,id',
            'address' => 'sometimes|string',
            'city' => 'sometimes|string',
            'state' => 'sometimes|string',
            'pincode' => 'sometimes|string',
            'is_active' => 'sometimes|boolean',
        ]);

        DB::beginTransaction();
        try {
            if (isset($validated['name']) || isset($validated['email']) || isset($validated['phone'])) {
                $student->user->update(array_filter([
                    'name' => $validated['name'] ?? null,
                    'email' => $validated['email'] ?? null,
                    'phone' => $validated['phone'] ?? null,
                ]));
            }

            $student->update(array_filter($validated, fn($key) => !in_array($key, ['name', 'email', 'phone']), ARRAY_FILTER_USE_KEY));

            DB::commit();

            return response()->json([
                'message' => 'Student updated successfully',
                'data' => $student->load(['user', 'class', 'section', 'academicYear']),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to update student', 'error' => $e->getMessage()], 500);
        }
    }

    public function destroy(Student $student)
    {
        try {
            $student->delete();
            return response()->json(['message' => 'Student deleted successfully']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to delete student', 'error' => $e->getMessage()], 500);
        }
    }
}

