<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\ContactService;
use App\Support\SchemaOrg;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    public function show()
    {
        return view('public.contact', [
            'jsonLd' => [
                SchemaOrg::organization(),
                SchemaOrg::breadcrumbs([['Home', route('home')], ['Contact', route('contact')]]),
            ],
        ]);
    }

    public function store(Request $request, ContactService $contact)
    {
        // Honeypot: bots fill hidden fields; pretend success without storing.
        if ($request->filled('website')) {
            return redirect()->route('contact')->with('status', 'Thank you! We will get back to you soon.');
        }

        $data = $request->validate(ContactService::RULES);

        $contact->submit($data, 'web', $request->ip());

        return redirect()->route('contact')->with('status', 'Thank you! We will get back to you soon.');
    }
}
