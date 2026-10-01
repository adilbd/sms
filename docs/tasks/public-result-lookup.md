# Add a public exam result lookup with printable marksheets

Status: done
Branch: feat/public-result-lookup

## Problem
Published exam results can only be seen by signed-in students and guardians (`/api/my/*`) and by admins. Bangladeshi school sites usually let a guardian check a result on the public website without logging in. The user pointed to the School360 portal (portal.tcgsc.edu.bd/result_publish/general_index) as the reference. It offers:
- lookup by Year + Exam + Student ID, or by Year + Exam + Class/Shift/Section + Group + Roll;
- a printable marksheet in Bangla or English;
- A4 or Legal paper, in portrait or landscape;
- an archive of past years.

That portal only asks for an ID or roll. Our student IDs and rolls are sequential and easy to guess, so anyone could page through every child's marks. The user chose to also **require the student's date of birth**.

The intended outcome:
- Our public site gets a **Results** page with the same two ways of searching, plus the date of birth.
- It shows a printable marksheet, with the language, page size and orientation chosen by the visitor.
- A **result archive** lists past published exams by year.
- The mobile-app API returns the same data.

Combined or Grand Final results built from several exams are out of scope. Each exam stays graded on its own (the user's decision).

## Scope
**In:**

1. **One public read path: `ResultService::publicLookup()`.** Both the website and the API go through it, so they always agree. Inputs:
   - `exam_id`, which must be a **published** exam;
   - **either** `student_id`, the student's ID code such as `20260047`, **or** `section_id` + `group` (nullable below Class 9) + `roll`;
   - `date_of_birth` (Y-m-d).

   Rules:
   - The match needs the student's active-or-historical enrolment in the exam's year **and** a matching `date_of_birth`.
   - Any mismatch returns the same generic "No result found" (404 on the API). The response never shows whether the student, roll or exam exists.
   - Only published exams are listed or matched.
   - Returns the same breakdown as `ResultService::breakdown()`.
2. **Throttle.**
   - A named `result-lookup` limiter: 10 lookups a minute per IP, plus 30 failed lookups an hour per IP. Every 429 includes `Retry-After`.
   - Failed lookups are counted in the service, like `AuthService` counts failed logins.
   - Applied to both the website POST and the API.
3. **Public website** (Blade; `Web\ResultController`; Bangla-first copy). It must follow the SEO rules in CLAUDE.md:
   - **`GET /results` (`results.index`)** is the lookup page, with two tabs: **By student ID** and **By class and roll**.
     - Year and exam selects list published exams only.
     - The class/shift/section select is built from the exam's classes and sections, labelled like "Class 9 – Section A (Morning)".
     - The group select appears only for Class 9 and above.
     - Inputs for date of birth, language (Bangla/English), page size (A4/Legal) and orientation (Portrait/Landscape).
     - Full SEO head, exactly one `<h1>`, and listed in the sitemap.
     - The selects need data without JS-only dependencies. Use a small JSON endpoint, `GET /results/options?exam_id=` (rate-limited), or render every published exam's sections into the page. Pick the simpler option and document it.
   - **`POST /results` (`results.show`)** renders the marksheet. Requirements:
     - CSRF-protected and POST only, so the date of birth never appears in a URL.
     - `noindex, nofollow`.
     - `Cache-Control: no-store`.
     - Exactly one `<h1>`.
     - A Print button.
     - Validation errors redirect back to the form with old input.
   - **`GET /results/archive` (`results.archive`)** lists published exams grouped by year. Each exam links to `/results?exam_id=…` with the exam preselected. Indexable, in the sitemap, one `<h1>`.
   - Add `results.index` to `MenuItem::ROUTES` and the `MenuForm.vue` route options. `MenuSeeder` adds a **ফলাফল** (Results) header item, matched so re-running adds no duplicates.
4. **Marksheet rendering.**
   - A Blade partial, `resources/views/public/results/marksheet.blade.php`, used by `results.show`.
   - **Language:**
     - `bn` uses Bangla labels, Bangla subject, class and section names where they exist, and **Bangla digits** (০–৯). Add a stateless `App\Support\BanglaNumber` helper.
     - `en` uses English throughout.
   - **Content:**
     - the school header from `InstituteSettingsService::profile()`;
     - the exam name and year;
     - the student's names, ID, class, section, shift, group and roll;
     - the subject table, showing parts, total, grade and point, with pairs combined and the 4th subject marked;
     - a failed 4th subject shown muted as not counted;
     - GPA, grade, result, subjects passed "N of M", total, and the class and section positions.
   - **Page setup:** `@page { size: A4|legal portrait|landscape }` from the visitor's choice, and the print CSS hides the site header and footer.
5. **Mobile and public API**, in `Api\PublicContentController` under `/api/public`:
   - `GET /api/public/exams` returns published exams with their classes and sections, for the selects.
   - `POST /api/public/results` takes the same inputs and returns the same breakdown resource.
   - Both are throttled and rate-limited the same way as the website.
6. **Admin report cards.** Add the same language (bn/en), page size and orientation selectors to `views/exams/ReportCards.vue`. Bangla digits use a shared JS helper.
7. **Docs.** Add a "Public results" note to CLAUDE.md. Update the SEO note: result pages are noindex, and the lookup and archive pages are in the sitemap.

**Out:**
- Combined or Grand Final results.
- Server-generated PDFs.
- SMS results.
- Showing tabulation or merit lists publicly.

## Guidelines that apply
- CLAUDE.md SEO rules: every public page has the `<x-seo>` head and exactly one `<h1>`. Result pages are noindex and must not be cached.
- `docs/architecture-guidelines.md`: a thin Web/Api controller with one shared service path, the same as Posts/Staff. Requests are validated in FormRequests.
- `docs/api-response-guidelines.md`: `data`-wrapped responses, 404 for "no result", and 429 with `Retry-After`.
- Domain rules: Bangla-first copy, "Class" not "Grade", Asia/Dhaka dates.

## Acceptance criteria
- [x] The lookup by student ID + DOB and by class/section/group/roll + DOB both return the marksheet for a published exam.
- [x] Any mismatch gives an identical "No result found". The response never reveals whether something exists.
- [x] Unpublished exams are never listed or shown.
- [x] Lookups are throttled per IP. Failed attempts are counted, and every 429 includes `Retry-After`.
- [x] The marksheet renders in Bangla (Bangla digits and names) or English, on A4 or Legal, in portrait or landscape. It prints cleanly.
- [x] The archive lists published exams by year.
- [x] Results is in the header menu.
- [x] `PublicSeoTest` covers the new pages:
  - full SEO head on each one;
  - exactly one `<h1>`;
  - the result view is noindex with `no-store`;
  - the lookup and archive pages are in the sitemap.
- [x] `/api/public/exams` and `/api/public/results` agree with the website.
- [x] Admin report cards have the same language, page and orientation options.
- [x] The full suite, Pint, `npm run build` and smoke all pass. CLAUDE.md is updated.

## Test cases
- [x] **Happy path.**
  - A seeded published exam: lookup by student ID + correct DOB shows the marksheet with GPA, grade and positions.
  - Lookup by section + group + roll + DOB shows the same student.
  - `language=bn` shows Bangla digits and names. `language=en` shows English.
  - `page=legal&orientation=landscape` sets the `@page` rule.
- [x] **Privacy.**
  - Each of these returns the same "No result found" (web) or 404 (API) with an identical body:
    - wrong DOB;
    - unknown ID;
    - unknown roll;
    - a student not enrolled in the exam's year;
    - an unpublished exam.
  - The DOB never appears in a URL. The marksheet response has `no-store` and `noindex`.
- [x] **Throttle.**
  - The 11th lookup in a minute from one IP gets 429 with `Retry-After`.
  - 30 failed lookups in an hour from one IP lock that IP out, and another IP still works.
  - Successful lookups don't count toward the failure limit.
- [x] **Validation.**
  - Neither the student ID nor the roll fields are given → 422 (API), or a redirect back with errors (web).
  - A group is required for Class 9+ roll lookups and forbidden below.
  - A bad date format.
  - `exam_id` is not a number.
- [x] **SEO.**
  - `/results` and `/results/archive` each have the full head and one `<h1>`, and are in the sitemap.
  - The result view has one `<h1>` and noindex.
  - A GET on `/results` with a preselected `exam_id` works.
- [x] **Menu.**
  - `results.index` is in `MenuItem::ROUTES`.
  - `MenuSeeder` adds ফলাফল once. Re-running it adds no duplicate.
- [x] **API.**
  - `/api/public/exams` lists published exams only, with their sections.
  - `/api/public/results` returns the same breakdown as the website.
- [x] **Unit tests.**
  - `BanglaNumber` formats digits and decimals, e.g. `3.64` → `৩.৬৪`.
  - `ResultService::publicLookup()` covers each rule with mocked repositories.
