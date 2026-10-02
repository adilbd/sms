<?php

namespace App\Exceptions;

use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The one answer to every admission status lookup that finds nothing: an unknown
 * application number or a wrong date of birth look the same on purpose, so a visitor
 * can't tell which part was wrong (same idea as ResultNotFoundException). The API renders
 * it as an ordinary 404; the website sends the visitor back to the form with the message,
 * without the date of birth in the flashed input.
 */
class AdmissionApplicationNotFoundException extends NotFoundHttpException
{
    public const MESSAGE = 'কোনো আবেদন পাওয়া যায়নি। আবেদন নম্বর ও জন্ম তারিখ যাচাই করে আবার চেষ্টা করুন। (No application found. Please check the application number and date of birth and try again.)';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }

    public function render(Request $request)
    {
        if ($request->is('api/*') || $request->expectsJson()) {
            return null;
        }

        return redirect()->route('admissions.status')->withInput($request->except('date_of_birth'))->withErrors(['lookup' => self::MESSAGE]);
    }
}
