# Add online admission applications

Status: done
Branch: feat/online-admission

## Problem
The public Admissions page is hardcoded English text that tells families to use the contact form. There is no way to apply online, and no admin workflow for applications.

Intended outcome:
- Families apply online for an open admission round.
- Admins review applications, can schedule a test or interview and record a score, then approve, waitlist or reject.
- An approved application converts into a real student (with a guardian login and an enrolment) in one click.

The full design is in `/Users/adil/.claude/plans/make-the-plan-for-radiant-flurry.md`, which is authoritative for the details.

## Decisions made with the user
- **Selection:** admin review, with an optional test or interview score. No lottery and no automatic merit list.
- **Fees:** no application fee. Any admission fee is collected after approval, through the Fees module's one-time Admission head.
- **Approval:** "Convert to student" goes through the existing `StudentService::create()`.
- **Uploads:** **only the student photo is required.** The birth certificate scan and the previous school's TC or report are optional. All files are stored privately and never get a public URL. The birth registration **number** is still a required field, because it blocks a duplicate application in the same round.

## Scope
Everything in the plan's Design section:
- **Rounds:** admission rounds per academic year, with classes and seats.
- **Applications:**
  - numbers in the form `ADM-{year}-{000001}`, from a locked counter that is selected first and retried on deadlock, like fee receipts;
  - files on the private disk, which admins download through an authenticated endpoint;
  - one application per child per round, matched on birth registration number.
- **Public website (Blade, Bangla-first):**
  - a dynamic `/admissions`;
  - `/admissions/apply/{round}`, protected by CSRF, a honeypot field, and a throttle of 5 submissions per IP per hour;
  - a confirmation page showing the application number, set to noindex and no-store;
  - `/admissions/status`, looked up by application number + date of birth. It returns the same response for every miss, is throttled, and is noindex and no-store.
- **Mobile API:** `/api/public/admission-rounds` and `/api/public/admission-status`, using the same service as the website.
- **Admin:**
  - CRUD for rounds;
  - an applications list with filters, and a detail view;
  - status transitions;
  - a seat limit when approving, which locks the round;
  - convert to student, into the round's academic year. This adds an optional target-year parameter to `StudentService`.
- **SPA:** the AdmissionRounds, AdmissionApplications and ApplicationDetail screens, plus a sidebar item under Students.
- **Privacy:** sensitive fields only appear in admin responses that need `edit-students`.
- **SEO:** exactly one `<h1>` per page, and the sitemap includes `/admissions` and the apply pages of open rounds.
- **Docs:** an "Admissions" note in CLAUDE.md.

**Out:**
- an application fee or payment gateway
- SMS or email notifications
- a lottery or merit list
- admit cards for the admission test

## Acceptance criteria
- [x] The public form submits with only a photo uploaded. Files are private. The application number is unique and sequential.
- [x] Every validation rule is enforced: the round must be open, no duplicate birth registration number, the group rule from Class 9, the photo is required, the file type and size limits, the honeypot, and the throttle.
- [x] The status lookup is private: every miss returns the same response, and pages are noindex and no-store.
- [x] Admin rounds and applications work. Transitions and the seat limit are enforced.
- [x] Convert creates the student, the guardian login and the enrolment in the round's year, and copies the photo.
- [x] File downloads are admin-only.
- [x] `PublicSeoTest` and the sitemap are updated.
- [ ] Migrations work on SQLite and MySQL, including rollback. SQLite is verified. MySQL is checked on Docker before the merge.
- [x] The full suite, Pint, `npm run build` and smoke all pass. CLAUDE.md is updated.

## Test cases
See the plan's "Tests" section. Key cases:
- [x] **Submission:**
  - A submission with only a photo succeeds.
  - A missing photo returns 422.
  - A duplicate birth registration number in the same round returns 422.
  - A closed round returns 404.
  - A filled honeypot is rejected.
  - Submitting too often returns 429.
- [x] **Application number:** several submissions get sequential numbers.
- [x] **Status lookup:**
  - The correct number + date of birth shows the status and test details.
  - Any miss returns an identical body.
- [x] **Status transitions:**
  - An illegal transition returns 422.
  - Approving beyond the seat limit returns 409.
  - Converting an application that isn't approved returns 409.
- [x] **Convert:**
  - It creates the student, the shared guardian login and the enrolment in the round's year.
  - The photo is copied to the student.
- [x] **Downloads:** a teacher gets 403. A public URL to a stored file returns 404.
- [x] **Rounds:** deleting a round that has applications returns 409.
- [x] **SEO:** one `<h1>` per page, and the noindex pages are noindex.
- [x] **API parity:** the public API matches the website.
