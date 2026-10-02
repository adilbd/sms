<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Routine\ReplaceSectionRoutineRequest;
use App\Http\Requests\Routine\ShowRoutineRequest;
use App\Http\Resources\SectionRoutineResource;
use App\Http\Resources\TeacherRoutineResource;
use App\Models\Section;
use App\Models\Staff;
use App\Services\RoutineService;
use Illuminate\Routing\Controllers\HasMiddleware;

/**
 * Class routines. Sections share the `*-classes` permissions; the teacher and section reads
 * are further limited to the caller's own work inside RoutineService, because the teacher
 * role holds view-classes.
 */
class RoutineController extends Controller implements HasMiddleware
{
    public function __construct(private RoutineService $routines) {}

    public static function middleware(): array
    {
        return static::resourcePermissions('classes', [
            'section' => 'view-classes',
            'saveSection' => 'edit-classes',
            'teacher' => 'view-classes',
        ]);
    }

    public function section(ShowRoutineRequest $request, Section $section)
    {
        return new SectionRoutineResource(
            $this->routines->forSection($section, $request->yearId(), $request->user())
        );
    }

    public function saveSection(ReplaceSectionRoutineRequest $request, Section $section)
    {
        return (new SectionRoutineResource($this->routines->replaceForSection($section, $request->validated())))
            ->additional(['message' => 'Routine saved successfully']);
    }

    public function teacher(ShowRoutineRequest $request, Staff $staff)
    {
        return new TeacherRoutineResource(
            $this->routines->forTeacher($staff, $request->yearId(), $request->user())
        );
    }
}
