<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Result\LookupResultRequest;
use App\Services\ResultService;
use App\Support\SchemaOrg;
use Illuminate\Http\Request;

/**
 * The public result pages: the lookup form, the archive of published exams and the
 * marksheet. The lookup goes through ResultService::publicLookup(), the same method
 * /api/public/results uses, so the website and the mobile app always agree.
 *
 * The form's selects are rendered from every published exam in the page itself (a few
 * lines of inline script filter them), so there is no separate options endpoint to
 * throttle; /api/public/exams serves the app the same data.
 */
class ResultController extends Controller
{
    public function __construct(private ResultService $results) {}

    public function index(Request $request)
    {
        $exams = $this->results->publishedExams();
        $examId = $request->query('exam_id');

        return view('public.results.index', [
            'exams' => $exams,
            'selectedExamId' => is_string($examId) && ctype_digit($examId) && $exams->contains('id', (int) $examId) ? (int) $examId : null,
            'jsonLd' => [
                SchemaOrg::breadcrumbs([['Home', route('home')], ['Results', route('results.index')]]),
            ],
        ]);
    }

    public function archive()
    {
        $exams = $this->results->publishedExams();

        return view('public.results.archive', [
            'years' => $exams->groupBy(fn ($exam) => $exam->academicYear->year),
            'jsonLd' => [
                SchemaOrg::breadcrumbs([
                    ['Home', route('home')],
                    ['Results', route('results.index')],
                    ['Result archive', route('results.archive')],
                ]),
            ],
        ]);
    }

    public function show(LookupResultRequest $request)
    {
        $result = $this->results->publicLookup($request->validated(), (string) $request->ip());

        $view = view('public.results.show', [
            'result' => $result,
            'language' => $request->validated('language') ?? 'bn',
            'paper' => $request->validated('page') ?? 'a4',
            'orientation' => $request->validated('orientation') ?? 'portrait',
        ]);

        // A marksheet is private to the family: never stored by a browser, proxy or CDN.
        return response($view)->header('Cache-Control', 'no-store, private');
    }
}
