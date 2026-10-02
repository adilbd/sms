@extends('layouts.public')

@section('seo')
    <x-seo :title="'ভর্তির আবেদন – '.($round->name_bn ?: $round->name_en)"
           :description="'অনলাইনে ভর্তির আবেদন ফর্ম: '.($round->name_bn ?: $round->name_en).'। শিক্ষার্থীর তথ্য, অভিভাবকের তথ্য ও ছবি দিয়ে আবেদন করুন। Online admission application form.'"
           :json-ld="$jsonLd" />
@endsection

@php
    $roundName = $round->name_bn ?: $round->name_en;
    $classes = $round->classes->sortBy(fn ($row) => $row->class->number);
    $genderLabels = ['male' => 'ছেলে (Male)', 'female' => 'মেয়ে (Female)', 'other' => 'অন্যান্য (Other)'];
    $religionLabels = ['islam' => 'ইসলাম', 'hinduism' => 'হিন্দু', 'buddhism' => 'বৌদ্ধ', 'christianity' => 'খ্রিষ্টান', 'other' => 'অন্যান্য'];
    $relationLabels = ['father' => 'পিতা (Father)', 'mother' => 'মাতা (Mother)', 'other' => 'অন্যান্য (Other)'];
@endphp

@section('content')
    <div class="container-page py-12">
        <x-breadcrumbs :items="['Home' => route('home'), 'Admissions' => route('admissions'), 'Apply' => null]" />

        <h1 class="mt-6 text-4xl font-bold text-gray-900">ভর্তির আবেদন <span class="text-2xl font-medium text-gray-500">(Admission application)</span></h1>
        <p class="mt-3 max-w-3xl text-gray-600">
            {{ $roundName }} · শেষ তারিখ: <strong>{{ \App\Support\BanglaDate::date($round->closes_at->toDateString()) }}</strong>.
            তারকা (*) চিহ্নিত ঘরগুলো পূরণ করা আবশ্যক। শুধু শিক্ষার্থীর ছবি বাধ্যতামূলক; অন্য কাগজ ঐচ্ছিক।
            <span class="block text-sm text-gray-500">(Fields marked * are required. Only the student photo is a required upload.)</span>
        </p>

        @if ($errors->any())
            <div class="mt-6 max-w-3xl rounded-lg bg-red-50 px-4 py-3 text-red-800" role="alert">
                <p class="font-medium">ফর্মে কিছু ভুল আছে। নিচের ঘরগুলো দেখুন। (Please fix the errors below.)</p>
                <ul class="mt-2 list-disc pl-5 text-sm">
                    @foreach ($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form id="apply-form" method="POST" action="{{ route('admissions.apply.store', $round) }}" enctype="multipart/form-data" class="mt-8 max-w-4xl space-y-8" novalidate>
            @csrf
            {{-- Honeypot: hidden from people, filled by bots. --}}
            <div class="hidden" aria-hidden="true">
                <label for="website">Website</label>
                <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
            </div>

            <fieldset class="card-public grid gap-5 p-6 sm:grid-cols-2">
                <legend class="px-2 text-xl font-semibold text-gray-900">কোন শ্রেণিতে (Class applied for)</legend>

                <div>
                    <label for="class_id" class="block text-sm font-medium text-gray-700">শ্রেণি (Class) *</label>
                    <select id="class_id" name="class_id" class="field mt-1" required>
                        <option value="">বেছে নিন (Select)</option>
                        @foreach ($classes as $row)
                            <option value="{{ $row->class_id }}" data-groups="{{ $row->class->hasGroups() ? 1 : 0 }}" @selected((string) old('class_id') === (string) $row->class_id)>{{ $row->class->name_bn ?: $row->class->name }}</option>
                        @endforeach
                    </select>
                    @error('class_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div id="group-field">
                    <label for="group" class="block text-sm font-medium text-gray-700">গ্রুপ (Group) – নবম শ্রেণি ও তার উপরে *</label>
                    <select id="group" name="group" class="field mt-1">
                        <option value="">–</option>
                        @foreach (\App\Support\AcademicGroup::VALUES as $group)
                            <option value="{{ $group }}" @selected(old('group') === $group)>{{ \App\Support\AcademicGroup::LABELS_BN[$group] }} ({{ \App\Support\AcademicGroup::LABELS_EN[$group] }})</option>
                        @endforeach
                    </select>
                    @error('group') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                @if ($shifts->count() > 1)
                    <div>
                        <label for="shift_id" class="block text-sm font-medium text-gray-700">পছন্দের শিফট (Preferred shift)</label>
                        <select id="shift_id" name="shift_id" class="field mt-1">
                            <option value="">–</option>
                            @foreach ($shifts as $shift)
                                <option value="{{ $shift->id }}" @selected((string) old('shift_id') === (string) $shift->id)>{{ $shift->name_bn ?: $shift->name_en }}</option>
                            @endforeach
                        </select>
                        @error('shift_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                @endif
            </fieldset>

            <fieldset class="card-public grid gap-5 p-6 sm:grid-cols-2">
                <legend class="px-2 text-xl font-semibold text-gray-900">শিক্ষার্থীর তথ্য (Student)</legend>

                @foreach ([
                    ['name_bn', 'নাম (বাংলা) – Name in Bangla', 'text', 'off'],
                    ['name_en', 'নাম (ইংরেজি) – Name in English (অন্তত একটি নাম আবশ্যক – at least one name is required)', 'text', 'off'],
                    ['date_of_birth', 'জন্ম তারিখ (Date of birth) *', 'date', 'off'],
                    ['birth_registration_number', 'জন্ম নিবন্ধন নম্বর, ১৭ অঙ্ক (Birth registration number) *', 'text', 'off'],
                ] as [$field, $label, $inputType, $autocomplete])
                    <div>
                        <label for="{{ $field }}" class="block text-sm font-medium text-gray-700">{{ $label }}</label>
                        <input id="{{ $field }}" name="{{ $field }}" type="{{ $inputType }}" value="{{ old($field) }}" autocomplete="{{ $autocomplete }}"
                               @if ($field === 'birth_registration_number') inputmode="numeric" maxlength="17" @endif
                               class="field mt-1" @error($field) aria-invalid="true" @enderror>
                        @error($field) <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                @endforeach

                <div>
                    <label for="gender" class="block text-sm font-medium text-gray-700">লিঙ্গ (Gender) *</label>
                    <select id="gender" name="gender" class="field mt-1">
                        <option value="">বেছে নিন (Select)</option>
                        @foreach (\App\Models\Student::GENDERS as $value)
                            <option value="{{ $value }}" @selected(old('gender') === $value)>{{ $genderLabels[$value] }}</option>
                        @endforeach
                    </select>
                    @error('gender') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="religion" class="block text-sm font-medium text-gray-700">ধর্ম (Religion)</label>
                    <select id="religion" name="religion" class="field mt-1">
                        <option value="">–</option>
                        @foreach (\App\Models\Student::RELIGIONS as $value)
                            <option value="{{ $value }}" @selected(old('religion') === $value)>{{ $religionLabels[$value] }}</option>
                        @endforeach
                    </select>
                    @error('religion') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="blood_group" class="block text-sm font-medium text-gray-700">রক্তের গ্রুপ (Blood group)</label>
                    <select id="blood_group" name="blood_group" class="field mt-1">
                        <option value="">–</option>
                        @foreach (\App\Models\Student::BLOOD_GROUPS as $value)
                            <option value="{{ $value }}" @selected(old('blood_group') === $value)>{{ $value }}</option>
                        @endforeach
                    </select>
                    @error('blood_group') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                @foreach ([
                    ['previous_school', 'আগের স্কুলের নাম (Previous school)'],
                    ['previous_class', 'আগের শ্রেণি (Previous class)'],
                ] as [$field, $label])
                    <div>
                        <label for="{{ $field }}" class="block text-sm font-medium text-gray-700">{{ $label }}</label>
                        <input id="{{ $field }}" name="{{ $field }}" type="text" value="{{ old($field) }}" class="field mt-1">
                        @error($field) <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                @endforeach
            </fieldset>

            <fieldset class="card-public grid gap-5 p-6 sm:grid-cols-2">
                <legend class="px-2 text-xl font-semibold text-gray-900">পিতা-মাতা ও অভিভাবক (Parents and guardian)</legend>

                @foreach ([
                    ['father_name_bn', 'পিতার নাম (বাংলা) – Father (Bangla)', 'text', 'off'],
                    ['father_name_en', 'পিতার নাম (ইংরেজি) – Father (English)', 'text', 'off'],
                    ['father_mobile', 'পিতার মোবাইল (Father\'s mobile)', 'tel', 'off'],
                    ['mother_name_bn', 'মাতার নাম (বাংলা) – Mother (Bangla)', 'text', 'off'],
                    ['mother_name_en', 'মাতার নাম (ইংরেজি) – Mother (English)', 'text', 'off'],
                    ['mother_mobile', 'মাতার মোবাইল (Mother\'s mobile)', 'tel', 'off'],
                ] as [$field, $label, $inputType, $autocomplete])
                    <div>
                        <label for="{{ $field }}" class="block text-sm font-medium text-gray-700">{{ $label }}</label>
                        <input id="{{ $field }}" name="{{ $field }}" type="{{ $inputType }}" value="{{ old($field) }}" autocomplete="{{ $autocomplete }}" class="field mt-1">
                        @error($field) <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                @endforeach

                <div>
                    <label for="guardian_relation" class="block text-sm font-medium text-gray-700">অভিভাবকের সম্পর্ক (Guardian is) *</label>
                    <select id="guardian_relation" name="guardian_relation" class="field mt-1">
                        @foreach (\App\Models\Student::GUARDIAN_RELATIONS as $value)
                            <option value="{{ $value }}" @selected(old('guardian_relation', 'father') === $value)>{{ $relationLabels[$value] }}</option>
                        @endforeach
                    </select>
                    @error('guardian_relation') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                @foreach ([
                    ['guardian_name', 'অভিভাবকের নাম (Guardian name) *', 'text', 'name'],
                    ['guardian_mobile', 'অভিভাবকের মোবাইল, ০১XXXXXXXXX (Guardian mobile) *', 'tel', 'tel'],
                    ['guardian_email', 'অভিভাবকের ইমেইল (Guardian email)', 'email', 'email'],
                ] as [$field, $label, $inputType, $autocomplete])
                    <div>
                        <label for="{{ $field }}" class="block text-sm font-medium text-gray-700">{{ $label }}</label>
                        <input id="{{ $field }}" name="{{ $field }}" type="{{ $inputType }}" value="{{ old($field) }}" autocomplete="{{ $autocomplete }}" class="field mt-1">
                        @error($field) <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                @endforeach
            </fieldset>

            <fieldset class="card-public grid gap-5 p-6 sm:grid-cols-2">
                <legend class="px-2 text-xl font-semibold text-gray-900">ঠিকানা (Address)</legend>

                <div class="sm:col-span-2">
                    <label for="present_address" class="block text-sm font-medium text-gray-700">বর্তমান ঠিকানা (Present address) *</label>
                    <textarea id="present_address" name="present_address" rows="3" class="field mt-1">{{ old('present_address') }}</textarea>
                    @error('present_address') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label for="permanent_address" class="block text-sm font-medium text-gray-700">স্থায়ী ঠিকানা (Permanent address)</label>
                    <textarea id="permanent_address" name="permanent_address" rows="3" class="field mt-1">{{ old('permanent_address') }}</textarea>
                    @error('permanent_address') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="district" class="block text-sm font-medium text-gray-700">জেলা (District)</label>
                    <input id="district" name="district" type="text" value="{{ old('district') }}" class="field mt-1">
                    @error('district') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </fieldset>

            <fieldset class="card-public grid gap-5 p-6">
                <legend class="px-2 text-xl font-semibold text-gray-900">কাগজপত্র (Documents)</legend>

                <div>
                    <label for="photo" class="block text-sm font-medium text-gray-700">শিক্ষার্থীর ছবি, JPG/PNG, সর্বোচ্চ ২ MB (Student photo) *</label>
                    <input id="photo" name="photo" type="file" accept="image/jpeg,image/png" class="field mt-1">
                    @error('photo') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="birth_certificate" class="block text-sm font-medium text-gray-700">জন্ম নিবন্ধন সনদ, JPG/PNG/PDF, সর্বোচ্চ ৫ MB (Birth certificate, optional)</label>
                    <input id="birth_certificate" name="birth_certificate" type="file" accept="image/jpeg,image/png,application/pdf" class="field mt-1">
                    @error('birth_certificate') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="previous_school_doc" class="block text-sm font-medium text-gray-700">আগের স্কুলের ছাড়পত্র/প্রতিবেদন, JPG/PNG/PDF, সর্বোচ্চ ৫ MB (Previous school TC or report, optional)</label>
                    <input id="previous_school_doc" name="previous_school_doc" type="file" accept="image/jpeg,image/png,application/pdf" class="field mt-1">
                    @error('previous_school_doc') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <p class="text-sm text-gray-500">ফাইলগুলো ব্যক্তিগতভাবে সংরক্ষিত হয় এবং শুধু বিদ্যালয়ের কর্মীরা দেখতে পারেন। (Files are stored privately and only school staff can open them.)</p>
            </fieldset>

            <div>
                <button type="submit" class="btn-public">আবেদন জমা দিন (Submit application)</button>
            </div>
        </form>

        {{-- Progressive enhancement only: without JavaScript the group select stays visible
             and the server enforces the Class 9 rule. --}}
        <script>
            (function () {
                var cls = document.getElementById('class_id');
                var groupField = document.getElementById('group-field');
                var group = document.getElementById('group');

                function showGroup() {
                    var option = cls.selectedOptions[0];
                    var needs = option && option.dataset.groups === '1';
                    groupField.style.display = needs ? '' : 'none';
                    group.disabled = !needs;
                    if (!needs) group.value = '';
                }

                cls.addEventListener('change', showGroup);
                showGroup();
            })();
        </script>
    </div>
@endsection
