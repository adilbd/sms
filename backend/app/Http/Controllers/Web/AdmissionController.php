<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admission\LookupStatusRequest;
use App\Http\Requests\Admission\SubmitApplicationRequest;
use App\Services\AdmissionService;
use App\Support\SchemaOrg;

/**
 * The public admission pages: the open rounds, the application form, the confirmation
 * and the status lookup. Everything goes through AdmissionService, the same service
 * /api/public/admission-* uses, so the website and the mobile app always agree.
 *
 * The confirmation and the status lookup are private to the family: the routes sit
 * behind PortalHeaders (noindex, no-store) and the pages carry a noindex meta tag. The
 * lookup is a POST so the date of birth never reaches a URL.
 */
class AdmissionController extends Controller
{
    public function __construct(private AdmissionService $admissions) {}

    public function index()
    {
        return view('public.admissions.index', [
            'rounds' => $this->admissions->openRounds(),
            'jsonLd' => [SchemaOrg::breadcrumbs([['Home', route('home')], ['Admissions', route('admissions')]])],
        ]);
    }

    public function apply(int $round)
    {
        $round = $this->admissions->findOpenRound($round);

        return view('public.admissions.apply', [
            'round' => $round,
            'shifts' => $this->admissions->activeShifts(),
            'jsonLd' => [SchemaOrg::breadcrumbs([
                ['Home', route('home')],
                ['Admissions', route('admissions')],
                ['Apply', $round->url()],
            ])],
        ]);
    }

    public function submit(SubmitApplicationRequest $request, int $round)
    {
        $application = $this->admissions->submit(
            $round,
            $request->applicationData(),
            array_filter([
                'photo' => $request->file('photo'),
                'birth_certificate' => $request->file('birth_certificate'),
                'previous_school_doc' => $request->file('previous_school_doc'),
            ]),
            (string) $request->ip(),
        );

        // Kept in the session (not flashed) so a refresh of the confirmation still shows
        // the number the family needs to check the status later.
        $request->session()->put('admission.submitted', $application->id);

        return redirect()->route('admissions.submitted');
    }

    public function submitted()
    {
        $id = session('admission.submitted');
        $application = $id ? $this->admissions->findSubmitted((int) $id) : null;

        if ($application === null) {
            return redirect()->route('admissions');
        }

        return view('public.admissions.submitted', ['application' => $application]);
    }

    public function statusForm()
    {
        return view('public.admissions.status');
    }

    public function statusShow(LookupStatusRequest $request)
    {
        return view('public.admissions.status-result', [
            'application' => $this->admissions->lookupStatus($request->validated(), (string) $request->ip()),
        ]);
    }
}
