@extends('layouts.public')

@section('seo')
    <x-seo title="ফলাফল অনুসন্ধান (Results)"
           description="শিক্ষার্থীর আইডি অথবা শ্রেণি ও রোল এবং জন্ম তারিখ দিয়ে প্রকাশিত পরীক্ষার ফলাফল দেখুন ও মার্কশিট প্রিন্ট করুন।"
           :json-ld="$jsonLd" />
@endsection

@php
    // Every published exam is rendered into the page, so the selects need no extra request.
    // A section belongs to the exams held for its class: data-exams lists their ids.
    $examLabel = fn ($exam) => ($exam->name_bn ?: $exam->name_en);
    $yearGroups = $exams->groupBy(fn ($exam) => $exam->academicYear->year);
    $sections = [];
    foreach ($exams as $exam) {
        foreach ($exam->classes as $class) {
            foreach ($class->sections as $section) {
                $sections[$section->id] ??= [
                    'number' => $class->number,
                    'label' => $class->name.' – '.$section->name.' ('.($section->shift?->name_en ?? '-').')',
                    'groups' => $class->hasGroups(),
                    'exams' => [],
                ];
                $sections[$section->id]['exams'][] = $exam->id;
            }
        }
    }
    uasort($sections, fn ($a, $b) => $a['number'] <=> $b['number']);
    $mode = old('student_id') || ! old('section_id') ? 'id' : 'roll';
    $currentExam = old('exam_id', $selectedExamId);
@endphp

@section('content')
    <div class="container-page py-12">
        <x-breadcrumbs :items="['Home' => route('home'), 'Results' => null]" />

        <h1 class="mt-6 text-4xl font-bold text-gray-900">ফলাফল <span class="text-2xl font-medium text-gray-500">(Exam results)</span></h1>
        <p class="mt-3 max-w-2xl text-gray-600">
            পরীক্ষা বেছে নিন, শিক্ষার্থীর আইডি অথবা শ্রেণি ও রোল এবং জন্ম তারিখ দিন। জন্ম তারিখ ছাড়া কোনো ফলাফল দেখানো হয় না।
            <a href="{{ route('results.archive') }}" class="font-medium text-primary-700 hover:underline">আগের ফলাফলের আর্কাইভ</a>
        </p>

        @if ($errors->has('lookup'))
            <p class="mt-6 rounded-lg bg-red-50 px-4 py-3 text-red-800" role="alert">{{ $errors->first('lookup') }}</p>
        @endif

        @if ($exams->isEmpty())
            <p class="mt-10 text-gray-500">এখনো কোনো ফলাফল প্রকাশিত হয়নি। (No results have been published yet.)</p>
        @else
            <form id="result-form" method="POST" action="{{ route('results.show') }}" class="mt-8 card-public max-w-3xl p-6 grid gap-5 sm:grid-cols-2" novalidate>
                @csrf

                <div>
                    <label for="year" class="block text-sm font-medium text-gray-700">বছর (Year)</label>
                    <select id="year" class="field mt-1">
                        <option value="">সব বছর (All years)</option>
                        @foreach ($yearGroups as $year => $group)
                            <option value="{{ $year }}">{{ $year }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="exam_id" class="block text-sm font-medium text-gray-700">পরীক্ষা (Exam)</label>
                    <select id="exam_id" name="exam_id" class="field mt-1" required>
                        <option value="">পরীক্ষা বেছে নিন (Select an exam)</option>
                        @foreach ($yearGroups as $year => $group)
                            @foreach ($group as $exam)
                                <option value="{{ $exam->id }}" data-year="{{ $year }}" @selected((string) $currentExam === (string) $exam->id)>{{ $examLabel($exam) }} – {{ $year }}</option>
                            @endforeach
                        @endforeach
                    </select>
                    @error('exam_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="sm:col-span-2" role="radiogroup" aria-label="অনুসন্ধানের ধরন">
                    <label class="mr-6 inline-flex items-center gap-2 text-sm font-medium text-gray-800">
                        <input type="radio" name="mode" value="id" @checked($mode === 'id')> শিক্ষার্থীর আইডি দিয়ে (By student ID)
                    </label>
                    <label class="inline-flex items-center gap-2 text-sm font-medium text-gray-800">
                        <input type="radio" name="mode" value="roll" @checked($mode === 'roll')> শ্রেণি ও রোল দিয়ে (By class and roll)
                    </label>
                </div>

                <div id="mode-id" class="sm:col-span-2">
                    <label for="student_id" class="block text-sm font-medium text-gray-700">শিক্ষার্থীর আইডি (Student ID)</label>
                    <input id="student_id" name="student_id" type="text" inputmode="numeric" maxlength="30" value="{{ old('student_id') }}" autocomplete="off" class="field mt-1">
                    @error('student_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div id="mode-roll" class="sm:col-span-2 grid gap-5 sm:grid-cols-3">
                    <div class="sm:col-span-2">
                        <label for="section_id" class="block text-sm font-medium text-gray-700">শ্রেণি, সেকশন ও শিফট (Class, section and shift)</label>
                        <select id="section_id" name="section_id" class="field mt-1">
                            <option value="">বেছে নিন (Select)</option>
                            @foreach ($sections as $id => $section)
                                <option value="{{ $id }}" data-exams="{{ implode(',', array_unique($section['exams'])) }}" data-groups="{{ $section['groups'] ? 1 : 0 }}" @selected((string) old('section_id') === (string) $id)>{{ $section['label'] }}</option>
                            @endforeach
                        </select>
                        @error('section_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="roll" class="block text-sm font-medium text-gray-700">রোল (Roll)</label>
                        <input id="roll" name="roll" type="number" min="1" max="99999" value="{{ old('roll') }}" class="field mt-1">
                        @error('roll') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div id="group-field" class="sm:col-span-3">
                        <label for="group" class="block text-sm font-medium text-gray-700">গ্রুপ (Group) – নবম শ্রেণি ও তার উপরে (Class 9 and above)</label>
                        <select id="group" name="group" class="field mt-1">
                            <option value="">–</option>
                            @foreach (\App\Support\AcademicGroup::VALUES as $group)
                                <option value="{{ $group }}" @selected(old('group') === $group)>{{ \App\Support\AcademicGroup::LABELS_BN[$group] }} ({{ \App\Support\AcademicGroup::LABELS_EN[$group] }})</option>
                            @endforeach
                        </select>
                        @error('group') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div>
                    <label for="date_of_birth" class="block text-sm font-medium text-gray-700">জন্ম তারিখ (Date of birth)</label>
                    <input id="date_of_birth" name="date_of_birth" type="date" value="{{ old('date_of_birth') }}" autocomplete="off" class="field mt-1" required>
                    @error('date_of_birth') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="language" class="block text-sm font-medium text-gray-700">মার্কশিটের ভাষা (Language)</label>
                    <select id="language" name="language" class="field mt-1">
                        <option value="bn" @selected(old('language', 'bn') === 'bn')>বাংলা</option>
                        <option value="en" @selected(old('language') === 'en')>English</option>
                    </select>
                </div>

                <div>
                    <label for="page" class="block text-sm font-medium text-gray-700">কাগজ (Page size)</label>
                    <select id="page" name="page" class="field mt-1">
                        <option value="a4" @selected(old('page', 'a4') === 'a4')>A4</option>
                        <option value="legal" @selected(old('page') === 'legal')>Legal</option>
                    </select>
                </div>

                <div>
                    <label for="orientation" class="block text-sm font-medium text-gray-700">ওরিয়েন্টেশন (Orientation)</label>
                    <select id="orientation" name="orientation" class="field mt-1">
                        <option value="portrait" @selected(old('orientation', 'portrait') === 'portrait')>পোর্ট্রেট (Portrait)</option>
                        <option value="landscape" @selected(old('orientation') === 'landscape')>ল্যান্ডস্কেপ (Landscape)</option>
                    </select>
                </div>

                <div class="sm:col-span-2">
                    <button type="submit" class="btn-public">ফলাফল দেখুন (View result)</button>
                </div>
            </form>

            {{-- Progressive enhancement only: without JavaScript every option stays visible and
                 both ways of searching can be filled in (the ID wins when it is given). --}}
            <script>
                (function () {
                    var form = document.getElementById('result-form');
                    var year = document.getElementById('year');
                    var exam = document.getElementById('exam_id');
                    var section = document.getElementById('section_id');
                    var groupField = document.getElementById('group-field');
                    var group = document.getElementById('group');
                    var panels = { id: document.getElementById('mode-id'), roll: document.getElementById('mode-roll') };

                    function toggle(option, show) { option.hidden = !show; option.disabled = !show; }

                    function filterExams() {
                        Array.prototype.forEach.call(exam.options, function (o) {
                            if (o.value) toggle(o, !year.value || o.dataset.year === year.value);
                        });
                        if (exam.selectedOptions[0] && exam.selectedOptions[0].disabled) exam.value = '';
                    }

                    function filterSections() {
                        Array.prototype.forEach.call(section.options, function (o) {
                            if (o.value) toggle(o, !exam.value || o.dataset.exams.split(',').indexOf(exam.value) !== -1);
                        });
                        if (section.selectedOptions[0] && section.selectedOptions[0].disabled) section.value = '';
                        showGroup();
                    }

                    function showGroup() {
                        var needs = section.selectedOptions[0] && section.selectedOptions[0].dataset.groups === '1';
                        groupField.style.display = needs ? '' : 'none';
                        group.disabled = !needs;
                        if (!needs) group.value = '';
                    }

                    function showMode() {
                        var mode = form.elements.mode.value;
                        Object.keys(panels).forEach(function (key) {
                            var active = key === mode;
                            panels[key].style.display = active ? '' : 'none';
                            Array.prototype.forEach.call(panels[key].querySelectorAll('input, select'), function (el) { el.disabled = !active; });
                        });
                        if (mode === 'roll') showGroup();
                    }

                    year.addEventListener('change', function () { filterExams(); filterSections(); });
                    exam.addEventListener('change', filterSections);
                    section.addEventListener('change', showGroup);
                    Array.prototype.forEach.call(form.elements.mode, function (r) { r.addEventListener('change', showMode); });
                    filterSections();
                    showMode();
                })();
            </script>
        @endif
    </div>
@endsection
