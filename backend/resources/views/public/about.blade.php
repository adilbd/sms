@extends('layouts.public')

@section('seo')
    <x-seo title="About Us"
           description="Learn about our school's mission, values, teaching approach and the dedicated staff who support every student."
           :json-ld="$jsonLd" />
@endsection

@section('content')
    <div class="container-page py-12">
        <x-breadcrumbs :items="['Home' => route('home'), 'About Us' => null]" />

        <article class="prose-public mt-6 max-w-3xl">
            <h1 class="text-4xl font-bold text-gray-900">About {{ config('seo.site_name') }}</h1>

            <p class="text-lg text-gray-600">
                We are a community of learners, teachers and families committed to helping every child
                grow academically, socially and personally.
            </p>

            <h2>Our mission</h2>
            <p>
                To provide a safe, inclusive and inspiring environment where students develop knowledge,
                character and the curiosity to keep learning for life.
            </p>

            <h2>Our values</h2>
            <ul>
                <li><strong>Respect</strong> for ourselves, each other and our environment.</li>
                <li><strong>Excellence</strong> in teaching, learning and everything we do.</li>
                <li><strong>Integrity</strong> in words and actions.</li>
                <li><strong>Community</strong> built on partnership between school and home.</li>
            </ul>

            <h2>How we teach</h2>
            <p>
                Small classes, qualified subject specialists and regular assessment mean we can track each
                student's progress closely. Parents can follow attendance, exam results and fees online.
            </p>

            <h2>Visit us</h2>
            <p>
                The best way to get to know us is to see the school in action.
                <a href="{{ route('contact') }}">Contact us</a> to arrange a tour, or read about
                <a href="{{ route('admissions') }}">admissions</a>.
            </p>
        </article>
    </div>
@endsection
