# Add print options for admission applications

Status: done
Branch: feat/admission-print

## Problem
Online admission is on `main`, but nothing can be printed. Families need a paper copy of their application to bring on the test or interview day. The admin and office staff need a printed form for the file and a printed list of applicants.

User decisions:
- **Applicant copy:** printable from the confirmation page right after submitting, and later from the status lookup (after application number + date of birth).
- **Admin:** a full single-application form from the detail page, plus a printable applicant list from the applications list. No batch printing of forms.

## Scope
**In:**

1. **Applicant copy (public, Blade, Bangla-first).**
   - A partial, `resources/views/public/admissions/copy.blade.php`. It shows:
     - the school header from `InstituteSettingsService::profile()`: logo and names in Bangla and English
     - the round name and the application number (Bangla digits via `BanglaNumber`)
     - the submitted date and time (Asia/Dhaka, `BanglaDate`)
     - the class, group and shift
     - the student's names, date of birth, gender, religion and blood group
     - the father's, mother's and guardian's names, the guardian's relation and the guardian's mobile
     - the present address and district
     - the previous school
     - the current status, and the test date, time and venue if scheduled
     - a note to bring the copy on test day
     - a guardian signature line
   - The birth registration number is shown **masked** except the last 4 digits. The admin note and test score are never shown.
   - A print-only CSS block: A4 portrait, hides the site header and footer, one page.
   - **Photo:**
     - The photo lives on the private disk, so it is shown through a **temporary signed URL**: `URL::temporarySignedRoute('admissions.copy-photo', now()+10 min, [application])`.
     - The route streams only the `photo` kind, requires a valid signature (403 otherwise), and is `no-store`/noindex.
     - Only the copy pages generate this URL.
   - **Confirmation page:** gets a "প্রিন্ট করুন (Print)" button. The page is still one-shot. It renders the copy inline, and print hides everything except the copy.
   - **Status lookup result page:** gets "আবেদনের কপি প্রিন্ট (Print application copy)". This renders the same copy, only after a successful POST lookup (application number + DOB).
     - It needs no new GET URL carrying personal data. Either use a second POST to the same verified lookup with `print=1`, or render the copy on the result page hidden behind print CSS. Choose the simpler option and document it.
     - The page stays noindex/no-store.
   - **API:** the public status endpoint is unchanged. The mobile app doesn't get the printable copy in this task.
2. **Admin single-application form** (`views/admissions/ApplicationPrint.vue` at `/admin/admissions/applications/:id/print`, linked from `ApplicationDetail.vue`).
   - The full application, printed on A4 portrait:
     - every field, including sensitive ones for users with `edit-students`, using the existing `withSensitive` response
     - the photo, fetched as a blob through the authenticated download, as `ApplicationDetail.vue` does
     - the status history, as far as the data has it: status, the decider and when, test date, venue and score, admin note
     - a document checklist: photo, birth certificate and TC, each received or missing
     - signature lines: applicant's guardian, admission committee, head teacher
   - A language toggle (Bangla/English) with Bangla digits via the existing `banglaNumber.js` and `banglaDate.js`. Print CSS hides the admin chrome, following the `ReportCards.vue` pattern.
3. **Admin applicant list print** (`views/admissions/ApplicationListPrint.vue`, opened from `AdmissionApplications.vue` with the current filters).
   - A table of every filtered application, not just the current page. Fetch pages from the existing list endpoint up to a cap of 1000 rows, and show a notice if the cap is hit.
   - Columns: number, application number, student name (Bangla/English), class/group, guardian name and mobile (`edit-students` only, otherwise hidden), status, test date, score.
   - The header shows the school name, round, the active filters, the printed time and the total count.
   - A4 landscape with repeated table headers on each page (`thead { display: table-header-group }`).
   - **Backend:** reuse `GET /api/admission-applications` and its filters. Add a server-side `per_page` bound if it isn't already 100. No new export endpoint.
4. **Docs:** extend the CLAUDE.md Admissions note with the print options and the signed photo route.

**Out:**
- Batch printing of full forms.
- PDF generation on the server.
- An admit card for the admission test.

## Acceptance criteria
- [x] The applicant can print a copy from the confirmation page and from a successful status lookup. The copy matches what was entered, masks the birth registration number, and never shows the admin note or score.
- [x] The private photo is shown through a temporary signed URL. An unsigned or expired URL returns 403, and the route serves only the photo.
- [x] The admin can print the single form, with photo, status and test details, and document checklist, in Bangla or English.
- [x] The admin can print the filtered applicant list, landscape, with repeated headers. Guardian contact is hidden without `edit-students`.
- [x] No personal data appears in a GET URL. Copy pages are noindex/no-store.
- [x] The full suite, Pint, `npm run build` and smoke all pass. CLAUDE.md is updated.

## Test cases
- [x] **Confirmation page:**
  - It contains the copy: application number, names, class, masked birth registration (`*************0123`).
  - It doesn't contain the admin note or score.
  - The photo `<img>` src is a signed URL.
- [x] **Status lookup:**
  - The correct number and DOB render the copy, including a scheduled test date and venue.
  - A miss renders no copy, with the same body as before.
- [x] **Signed photo route:**
  - A valid signature gets 200 and the image.
  - A missing or tampered signature gets 403.
  - An expired link (time travel) gets 403.
  - Asking for another kind (e.g. `birth_certificate`) gets 404 or 403.
  - Responses are `no-store` and noindex.
- [x] **No-store and noindex:** both hold on the confirmation and status pages that include the copy.
- [x] **Admin list:** the list endpoint returns every filtered row across pages, and `per_page` is bounded.
- [x] **SPA:** there is no SPA test runner. `npm run build` passes, and I'll check both print views in Chrome on Docker before merging.

## Docker and browser check
- [x] Status lookup for `ADM-2026-000001` with the correct DOB returned 200, no-store and noindex, with one `<h1>`. The copy shows the birth registration masked as `*************0123`. The full number, the admin note and the score are absent.
- [x] Signed photo URL returns 200 `image/png`. A tampered or unsigned URL returns 403.
- [x] The admin single form (`/admin/admissions/applications/1/print`) is in Bangla, with all fields, the photo loaded through the authenticated blob, the test date, venue and score ৭৮.৫০, and A4 portrait. There are no console errors.
- [x] The admin list print (`/admin/admissions/print?round_id=1`) is landscape, with a repeating header and a Bangla header block. The guardian mobile shows for an admin.

## Follow-ups (from review)
- The signed photo URL lasts 10 minutes, so a reprint after that may show a broken photo. Consider 30–60 minutes.
- The admin print views load Noto Sans Bengali from Google Fonts at runtime. Consider bundling the font.
- `ApplicationPrint.vue`: change the second school name from `<h2>` to `<p>`.
