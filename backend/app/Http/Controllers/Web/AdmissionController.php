<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admission\LookupStatusRequest;
use App\Http\Requests\Admission\SubmitApplicationRequest;
use App\Models\AdmissionApplication;
use App\Services\AdmissionService;
use App\Support\SchemaOrg;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

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

        // One-shot: the confirmation pulls it, so the number is not shown again on refresh.
        $request->session()->put('admission.submitted', $application->id);

        return redirect()->route('admissions.submitted');
    }

    public function submitted()
    {
        $id = session()->pull('admission.submitted');
        $application = $id ? $this->admissions->findSubmitted((int) $id) : null;

        if ($application === null) {
            return redirect()->route('admissions');
        }

        return view('public.admissions.submitted', ['application' => $application, 'photoUrl' => $this->photoUrl($application)]);
    }

    public function statusForm()
    {
        return view('public.admissions.status');
    }

    public function statusShow(LookupStatusRequest $request)
    {
        $application = $this->admissions->lookupStatus($request->validated(), (string) $request->ip());

        return view('public.admissions.status-result', [
            'application' => $application,
            'photoUrl' => $this->photoUrl($application),
        ]);
    }

    /**
     * Streams the applicant's photo from the private disk. Reached only through the
     * temporary signed URL the copy pages generate (the `signed` middleware answers 403
     * otherwise); no other file kind is served here.
     */
    public function copyPhoto(AdmissionApplication $application)
    {
        $file = $this->admissions->photoFile($application);

        return Storage::disk($file['disk'])->response($file['path'], null, [
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }

    private function photoUrl(AdmissionApplication $application): ?string
    {
        return $application->photo_path === null
            ? null
            : URL::temporarySignedRoute('admissions.copy-photo', now()->addMinutes(10), ['application' => $application->id]);
    }
}
