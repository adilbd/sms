<?php

namespace App\Exceptions;

use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The one answer to every public result lookup that finds nothing: an unknown or
 * unpublished exam, an unknown ID or roll, a wrong date of birth, a student who was not
 * enrolled that year. All look the same on purpose, so a visitor can't tell which part was
 * wrong. The API renders it as an ordinary 404; the website sends the visitor back to the
 * form with the message.
 */
class ResultNotFoundException extends NotFoundHttpException
{
    public const MESSAGE = 'No result found. Please check the details and try again.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }

    public function render(Request $request)
    {
        if ($request->is('api/*') || $request->expectsJson()) {
            return null;
        }

        return redirect()->route('results.index')->withInput()->withErrors(['lookup' => self::MESSAGE]);
    }
}
