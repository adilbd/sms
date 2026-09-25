@extends('layouts.public')

@section('seo')
    <x-seo title="Admissions"
           description="How to apply: admission process, required documents, age criteria and key dates. Start your child's application today."
           :json-ld="$jsonLd" />
@endsection

@section('content')
    <div class="container-page py-12">
        <x-breadcrumbs :items="['Home' => route('home'), 'Admissions' => null]" />

        <article class="prose-public mt-6 max-w-3xl">
            <h1 class="text-4xl font-bold text-gray-900">Admissions</h1>

            <p class="text-lg text-gray-600">
                We welcome applications throughout the year, subject to availability in each class.
            </p>

            <h2>How to apply</h2>
            <ol>
                <li><strong>Enquire</strong> using our <a href="{{ route('contact') }}">contact form</a> or by phone.</li>
                <li><strong>Visit</strong> the school and meet our team.</li>
                <li><strong>Submit</strong> the application form with the required documents.</li>
                <li><strong>Assessment</strong>: an age-appropriate informal assessment and a meeting with parents.</li>
                <li><strong>Offer</strong>: we confirm a place and send the enrolment pack.</li>
            </ol>

            <h2>Documents required</h2>
            <ul>
                <li>Birth certificate</li>
                <li>Previous school report and transfer certificate (if applicable)</li>
                <li>Recent passport-size photographs</li>
                <li>Proof of address and parent/guardian ID</li>
            </ul>

            <h2>Fees</h2>
            <p>
                Fee structures vary by class. Please <a href="{{ route('contact') }}">contact the admissions office</a>
                for the current fee schedule and payment options.
            </p>
        </article>

        <div class="mt-10">
            <a href="{{ route('contact') }}" class="btn-public">Start an enquiry</a>
        </div>
    </div>
@endsection
