<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
class ExamScheduleController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        // Schedules are part of exam management.
        return static::resourcePermissions('exams');
    }
    public function index(Request $request) { return response()->json(['data' => []]); }
    public function store(Request $request) { return response()->json(['message' => 'Coming soon'], 501); }
    public function show($id) { return response()->json(['message' => 'Coming soon'], 501); }
    public function update(Request $request, $id) { return response()->json(['message' => 'Coming soon'], 501); }
    public function destroy($id) { return response()->json(['message' => 'Coming soon'], 501); }
}
