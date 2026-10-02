<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\PortalChangePasswordRequest;
use App\Http\Requests\Portal\PortalMarksheetRequest;
use App\Http\Requests\Portal\PortalPageRequest;
use App\Http\Requests\Portal\PortalReceiptRequest;
use App\Services\AuthService;
use App\Services\PortalService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The student and guardian portal pages (/portal). Every page reads through PortalService,
 * which calls the same service methods as `/api/my/*`. The `portal` middleware group has
 * already required a signed-in student or guardian; `PortalService::resolveStudent()` picks
 * the record (a student's own, or the guardian's chosen child, 403 for anyone else's).
 */
class PortalController extends Controller
{
    private const SESSION_STUDENT = 'portal.student_id';

    public function __construct(private PortalService $portal, private AuthService $auth) {}

    public function dashboard(PortalPageRequest $request)
    {
        [$user, $student] = $this->context($request);

        return $this->page('portal.dashboard', $request, $student, [
            'summary' => $this->portal->dashboard($user, $student),
        ]);
    }

    public function profile(PortalPageRequest $request)
    {
        [, $student] = $this->context($request);

        return $this->page('portal.profile', $request, $student);
    }

    public function changePassword(PortalChangePasswordRequest $request)
    {
        $this->auth->changePassword(
            $request->user(),
            $request->validated('current_password'),
            $request->validated('new_password'),
        );

        // Keep this session, end every other one for the account (AuthenticateSession checks the hash).
        Auth::guard('web')->logoutOtherDevices($request->validated('new_password'));
        $request->session()->regenerate();

        return redirect()->route('portal.profile')->with('status', 'password-changed');
    }

    public function results(PortalPageRequest $request)
    {
        [$user, $student] = $this->context($request);

        return $this->page('portal.results', $request, $student, [
            'results' => $this->portal->results($user, $student),
        ]);
    }

    public function result(PortalMarksheetRequest $request, int $exam)
    {
        [$user, $student] = $this->context($request);

        return $this->page('portal.result', $request, $student, [
            'result' => $this->portal->result($user, $student, $exam),
            'language' => $request->validated('language') ?? 'bn',
            'paper' => $request->validated('page') ?? 'a4',
            'orientation' => $request->validated('orientation') ?? 'portrait',
        ]);
    }

    public function attendance(PortalPageRequest $request)
    {
        [$user, $student] = $this->context($request);
        $month = $this->portal->attendance($user, $student, $request->validated('month'));
        $current = CarbonImmutable::createFromFormat('!Y-m', $month['month'], 'UTC');

        return $this->page('portal.attendance', $request, $student, [
            'month' => $month,
            'previousMonth' => $current->subMonth()->format('Y-m'),
            'nextMonth' => $current->addMonth()->format('Y-m'),
        ]);
    }

    public function fees(PortalPageRequest $request)
    {
        [$user, $student] = $this->context($request);

        return $this->page('portal.fees', $request, $student, [
            'fees' => $this->portal->fees($user, $student),
        ]);
    }

    public function receipt(PortalReceiptRequest $request, int $payment)
    {
        [, $student] = $this->context($request);

        return $this->page('portal.receipt', $request, $student, [
            'payment' => $this->portal->receipt($student, $payment),
            'language' => $request->validated('language') ?? 'bn',
            'paper' => $request->validated('page') ?? 'a4',
        ]);
    }

    public function exams(PortalPageRequest $request)
    {
        [$user, $student] = $this->context($request);

        return $this->page('portal.exams', $request, $student, [
            'exams' => $this->portal->upcomingExams($user, $student),
        ]);
    }

    public function routine(PortalPageRequest $request)
    {
        [, $student] = $this->context($request);

        return $this->page('portal.routine', $request, $student, [
            'routine' => $this->portal->routine($student),
            'language' => $request->validated('language') ?? 'bn',
        ]);
    }

    /**
     * @return array{0: \App\Models\User, 1: \App\Models\Student}
     */
    private function context(PortalPageRequest $request): array
    {
        $user = $request->user();
        $student = $this->portal->resolveStudent($user, $request->studentId(), session(self::SESSION_STUDENT));

        // A guardian's choice sticks across pages.
        if ($user->hasRole('parent')) {
            session([self::SESSION_STUDENT => $student->id]);
        }

        return [$user, $student];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function page(string $view, Request $request, $student, array $data = [])
    {
        return view($view, [
            'user' => $request->user(),
            'student' => $student,
            'children' => $this->portal->children($request->user()),
            ...$data,
        ]);
    }
}
