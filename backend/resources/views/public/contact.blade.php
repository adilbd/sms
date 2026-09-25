@extends('layouts.public')

@section('seo')
    <x-seo title="Contact Us"
           description="Get in touch with our school office: address, phone, email and an enquiry form for admissions, visits and general questions."
           :json-ld="$jsonLd" />
@endsection

@section('content')
    @php($org = config('seo.organization'))
    <div class="container-page py-12">
        <x-breadcrumbs :items="['Home' => route('home'), 'Contact' => null]" />

        <h1 class="mt-6 text-4xl font-bold text-gray-900">Contact us</h1>

        <div class="mt-8 grid gap-10 lg:grid-cols-3">
            <div class="space-y-4 text-gray-700">
                <div>
                    <h2 class="font-semibold text-gray-900">Address</h2>
                    <address class="not-italic">
                        {{ $org['street'] }}<br>
                        {{ $org['city'] }}{{ $org['region'] ? ', '.$org['region'] : '' }} {{ $org['postal_code'] }}
                    </address>
                </div>
                <div>
                    <h2 class="font-semibold text-gray-900">Phone</h2>
                    <a class="hover:text-primary-700" href="tel:{{ preg_replace('/[^\d+]/', '', $org['phone']) }}">{{ $org['phone'] }}</a>
                </div>
                <div>
                    <h2 class="font-semibold text-gray-900">Email</h2>
                    <a class="hover:text-primary-700" href="mailto:{{ $org['email'] }}">{{ $org['email'] }}</a>
                </div>
            </div>

            <div class="lg:col-span-2 card-public p-6">
                <h2 class="text-xl font-semibold text-gray-900">Send us a message</h2>

                @if (session('status'))
                    <p class="mt-4 rounded-lg bg-green-50 px-4 py-3 text-green-800" role="status">{{ session('status') }}</p>
                @endif

                <form method="POST" action="{{ route('contact.store') }}" class="mt-6 grid gap-4 sm:grid-cols-2" novalidate>
                    @csrf
                    {{-- Honeypot --}}
                    <div class="hidden" aria-hidden="true">
                        <label for="website">Website</label>
                        <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
                    </div>

                    @foreach ([
                        ['name', 'Full name', 'text', true, 'name'],
                        ['email', 'Email', 'email', true, 'email'],
                        ['phone', 'Phone (optional)', 'tel', false, 'tel'],
                        ['subject', 'Subject (optional)', 'text', false, 'off'],
                    ] as [$field, $label, $inputType, $required, $autocomplete])
                        <div>
                            <label for="{{ $field }}" class="block text-sm font-medium text-gray-700">{{ $label }}</label>
                            <input id="{{ $field }}" name="{{ $field }}" type="{{ $inputType }}" value="{{ old($field) }}"
                                   autocomplete="{{ $autocomplete }}" class="field mt-1" @required($required)
                                   @error($field) aria-invalid="true" aria-describedby="{{ $field }}-error" @enderror>
                            @error($field)
                                <p id="{{ $field }}-error" class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    @endforeach

                    <div class="sm:col-span-2">
                        <label for="message" class="block text-sm font-medium text-gray-700">Message</label>
                        <textarea id="message" name="message" rows="5" class="field mt-1" required
                                  @error('message') aria-invalid="true" aria-describedby="message-error" @enderror>{{ old('message') }}</textarea>
                        @error('message')
                            <p id="message-error" class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="sm:col-span-2">
                        <button type="submit" class="btn-public">Send message</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
