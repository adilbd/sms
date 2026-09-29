<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Shift;
use App\Models\Staff;
use App\Services\ShiftService;
use App\Services\StaffService;
use App\Support\SchemaOrg;
use Illuminate\Http\Request;

/**
 * The public স্কুল প্রশাসন (School Administration) pages: 7 index pages (current and
 * former head/assistant head/teachers/staff) plus a profile page. Reads go through
 * StaffService, the same service /api/public/staff uses, so both always agree.
 */
class StaffController extends Controller
{
    public function __construct(private StaffService $staff, private ShiftService $shifts) {}

    public function head(Request $request)
    {
        return $this->renderInline($request, Staff::POSITION_HEAD, false, 'Head Teacher', 'staff.head');
    }

    public function assistantHead(Request $request)
    {
        return $this->renderInline($request, Staff::POSITION_ASSISTANT_HEAD, false, 'Assistant Head Teacher', 'staff.assistant_head');
    }

    public function teachers(Request $request)
    {
        return $this->renderList($request, Staff::POSITION_TEACHER, false, 'Teachers', 'staff.teachers');
    }

    public function employees(Request $request)
    {
        return $this->renderList($request, Staff::POSITION_STAFF, false, 'Staff', 'staff.employees');
    }

    public function exHeads(Request $request)
    {
        return $this->renderList($request, Staff::POSITION_HEAD, true, 'Former Heads', 'staff.ex_heads');
    }

    public function exTeachers(Request $request)
    {
        return $this->renderList($request, Staff::POSITION_TEACHER, true, 'Former Teachers', 'staff.ex_teachers');
    }

    public function exEmployees(Request $request)
    {
        return $this->renderList($request, Staff::POSITION_STAFF, true, 'Former Staff', 'staff.ex_employees');
    }

    public function show(int $staff)
    {
        $member = $this->staff->publicFind($staff);

        return view('public.staff.show', [
            'member' => $member,
            'jsonLd' => [
                SchemaOrg::breadcrumbs([
                    ['Home', route('home')],
                    ['School Administration', route('staff.teachers')],
                    [$member->name(), $member->url()],
                ]),
                SchemaOrg::person($member),
            ],
        ]);
    }

    private function renderInline(Request $request, string $position, bool $former, string $title, string $routeName)
    {
        $shift = $this->resolveShiftFilter($request);
        $activeShifts = $this->shifts->activeShifts();

        $members = $this->staff->publicList([
            'position' => $position,
            'former' => $former,
            'shift' => $shift?->slug,
        ]);

        return view('public.staff.heads', [
            'title' => $title,
            'members' => $members,
            'shift' => $shift,
            'shifts' => $activeShifts->count() > 1 ? $activeShifts : collect(),
            'routeName' => $routeName,
            'jsonLd' => [
                SchemaOrg::breadcrumbs([
                    ['Home', route('home')],
                    ['School Administration', route('staff.teachers')],
                    [$title, route($routeName)],
                ]),
            ],
        ]);
    }

    private function renderList(Request $request, string $position, bool $former, string $title, string $routeName)
    {
        $shift = $this->resolveShiftFilter($request);
        $activeShifts = $this->shifts->activeShifts();

        $members = $this->staff->publicList([
            'position' => $position,
            'former' => $former,
            'shift' => $shift?->slug,
        ]);

        return view('public.staff.list', [
            'title' => $title,
            'members' => $members,
            'former' => $former,
            'shift' => $shift,
            'shifts' => $activeShifts->count() > 1 ? $activeShifts : collect(),
            'routeName' => $routeName,
            'jsonLd' => [
                SchemaOrg::breadcrumbs([
                    ['Home', route('home')],
                    ['School Administration', route('staff.teachers')],
                    [$title, route($routeName)],
                ]),
            ],
        ]);
    }

    private function resolveShiftFilter(Request $request): ?Shift
    {
        $slug = $request->query('shift');

        return $slug ? $this->staff->resolveActiveShift($slug) : null;
    }
}
