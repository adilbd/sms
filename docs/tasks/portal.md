# Add the student and guardian portal on the public site

Status: done
Branch: feat/portal

## Problem
Students and guardians can sign in, and `/api/my/*` already returns their own profile, results, exam schedule, attendance and fees. But nothing uses those endpoints yet. The admin SPA is for staff, and the public site has no signed-in area.

The intended outcome is a **Bangla-first, mobile-friendly portal** on the public website at `/portal`, rendered with Blade:
- A student sees their own records.
- A guardian sees each of their children, with a switcher between them.
- The portal reads through the same services as `/api/my/*`, so the two always agree.

This is Task 7 of 7 in `/Users/adil/.claude/plans/make-the-plan-for-radiant-flurry.md`. Read the Task 7 section there.

## Scope
**In:**

1. **Auth (session, web guard).** The admin SPA stays on bearer tokens.
   - **Login:** `GET /portal/login` shows the form and `POST /portal/login` signs in. The form takes a student ID or guardian mobile (email also works) and a password, protected by CSRF.
     - It goes through `AuthService::login()`, so it uses the same lookup, the same throttle with `Retry-After`, and the same inactive-account rule.
     - After that it does a session login (`Auth::guard('web')->login($user)`) and calls `session()->regenerate()`.
     - Errors show as a generic bilingual message.
   - **Who can sign in:** only users with the `student` or `parent` role. A staff user is not logged into the portal session; they're redirected to `/admin/login` with a note. Choose and document whether a staff user is rejected outright or redirected.
   - **Logout:** `POST /portal/logout` invalidates the session and regenerates the token.
   - **Protected pages:** every page except login is protected by a `portal` middleware group. It requires an authenticated web user with the `student` or `parent` role, and sends guests to `/portal/login`.
   - **Headers:** every portal response carries `Cache-Control: no-store, private` and `X-Robots-Tag: noindex`.
2. **Pages** (`Web\PortalController` plus Blade under `resources/views/portal/`, using `layouts/public` and `<x-seo>`).
   - Every page has `noindex, nofollow`, exactly one `<h1>`, and Bangla-first copy using `BanglaNumber` for digits.
   - **`/portal`, the dashboard:**
     - The student's or the selected child's summary: name, class, section, shift, roll, group and photo.
     - This month's attendance percentage.
     - The latest published result's GPA and grade.
     - Outstanding fees.
     - Upcoming exam dates.
   - **`/portal/profile`:** the profile, plus change password. Change password goes through `AuthService::changePassword`, so it revokes other tokens and bumps the trust version.
   - **Results:**
     - `/portal/results` lists the published exams.
     - `/portal/results/{exam}` shows that exam's marksheet, reusing `resources/views/public/results/marksheet.blade.php` with no date of birth needed.
     - It has the same Bangla/English, A4/Legal and portrait/landscape options and a Print button.
     - An unpublished exam returns 404.
   - **`/portal/attendance?month=YYYY-MM`:** a calendar or grid of the month (present, absent, late, leave, holiday, weekly holiday), the totals and percentage, and the year to date. Month navigation is available.
   - **Fees:**
     - `/portal/fees` shows dues (period, head, net, paid, outstanding, status), payments with receipt numbers, and the outstanding total.
     - `/portal/fees/receipts/{payment}` shows a printable receipt for the caller's own payment. Another student's payment returns 404.
   - **`/portal/exams`:** the upcoming and current exam schedule for the student's subjects.
3. **The guardian's child switcher.**
   - Every page accepts `?student={id}`, which is checked against `StudentService::childrenOf()`. Anyone who isn't the guardian's child gets 403.
   - The chosen child is remembered in the session. With one child, no switcher is shown.
   - A student user ignores `?student`; it's always their own record.
4. **One read path.** The portal uses the same service methods as `MyRecordsController`:
   - `StudentService::findOwn` / `findChildOf` / `childrenOf`
   - `ResultService` (own results, own schedule)
   - `AttendanceService` (own month)
   - the fees services (own fees)

   If a needed method is only on the controller, move it into the service. The portal and `/api/my/*` must return the same data. Add a test comparing one page's numbers with the API.
5. **Header link.**
   - Add `portal.login` to `MenuItem::ROUTES` and the `MenuForm.vue` options.
   - `MenuSeeder` adds a "লগইন / পোর্টাল" header item once, matched on `route_name`.
   - When signed in, the public header shows "আমার পোর্টাল" and a logout link instead. Keep it simple, inside the existing header component.
6. **Receipt fixes** (carried over from Task 5, shared by the admin and portal receipts):
   - Bangla dates use Bangla month names and পূর্বাহ্ন / অপরাহ্ন, not "Oct" or "pm". Add a stateless `App\Support\BanglaDate` for Blade, and a `banglaDate.js` counterpart used by `FeeReceipt.vue`.
   - Cash's Bangla label becomes "নগদ টাকা", so it isn't confused with the Nagad service "নগদ (মোবাইল)", in both the JS constants and any PHP labels.
7. **SEO and sitemap.** Portal pages are never in the sitemap. `PublicSeoTest` gains the portal login page, which has a full head and one `<h1>` and is noindex.
8. **Docs.** Add a "Portal" note to CLAUDE.md.

**Out:**
- Online fee payment.
- Notifications or messaging.
- Editing the profile, other than the password.
- A mobile app.

## Acceptance criteria
- [x] Students and guardians can sign in to `/portal` with a session login. Staff can't use the portal. Logout, CSRF, the throttle and `no-store` all work.
- [x] Every page shows only the caller's own data, or the chosen child's, read through the same services as `/api/my/*`.
- [x] The guardian's switcher works, and another family's child gets 403.
- [x] Results show published exams only and print correctly. Attendance and fees match the API. Receipts are own-only.
- [x] Every portal page is noindex with one `<h1>` and isn't in the sitemap.
- [x] Receipt dates are fully Bangla, and cash is labelled "নগদ টাকা".
- [x] The header has the login/portal link.
- [x] The full suite, Pint, `npm run build` and smoke all pass. CLAUDE.md is updated.

## Test cases
- [x] **Login.**
  - A student signing in with their student ID and a guardian signing in with their mobile (given as `+88…`) both land on `/portal`.
  - A wrong password gives the generic error.
  - The throttle gives 429 with `Retry-After`, using the same limiter as the API.
  - An inactive account is refused.
  - A staff user is refused or redirected to `/admin/login`.
  - A login without a CSRF token gets 419.
  - Logout ends the session.
- [x] **Access.**
  - A guest is redirected to the login page from every portal page.
  - A staff session can't open portal pages.
  - Every response has `no-store` and noindex.
- [x] **Student.**
  - The dashboard numbers match `/api/my/student`, `/api/my/results`, `/api/my/attendance` and `/api/my/fees`.
  - `?student=` set to another student's ID is ignored, and the student sees their own record.
- [x] **Guardian.**
  - With 2 children, the switcher is visible and each child's pages show that child's data.
  - Another family's child gets 403.
  - With 1 child, no switcher is shown.
- [x] **Results.**
  - Only published exams are listed.
  - An unpublished exam's marksheet returns 404.
  - The marksheet renders in Bangla and in English, with the `@page` options.
- [x] **Attendance.** The month grid shows holidays and weekly holidays. The totals and percentage match `/api/my/attendance`.
- [x] **Fees.** The dues and outstanding total match `/api/my/fees`. Your own receipt renders. Another student's receipt returns 404.
- [x] **Profile.**
  - Changing the password works.
  - A wrong current password gets 422.
  - The trust version is bumped.
- [x] **SEO.**
  - Each page has exactly one `<h1>` and is noindex.
  - The portal is absent from the sitemap.
  - The login page is in `PublicSeoTest`.
- [x] **Receipt.**
  - `BanglaDate` formats `2026-10-01 15:07` in Asia/Dhaka as Bangla day, month and year with অপরাহ্ন.
  - `banglaDate.js` matches it.
  - Cash shows as "নগদ টাকা".
- [x] **Menu.** `MenuSeeder` adds the portal link once, and re-running it adds no duplicate.

## Docker and browser check
- [x] A guardian signs in with `+8801999000001` at `/portal/login`, which is noindex with one `<h1>`. The switcher shows both children, তানভীর আহমেদ and করিম হোসেন. The header shows আমার পোর্টাল and লগআউট. There are no console errors.
- [x] Tanvir's outstanding fees show ৳৭,৯৯০.০০, matching `/api/my/children/1/fees` (`7990.00`). Switching to Karim works. Another family's child (`?student=41`) returns 403. Responses carry `no-store, private`.
- [x] Receipt `২০২৬-০০০০০৩` shows "১ অক্টোবর ২০২৬, অপরাহ্ন ৯:০৭" and the method "নগদ টাকা".
- [x] Review should-fix: changing the password now ends other portal sessions (`AuthenticateSession` plus `logoutOtherDevices`).

## Follow-ups (from review)
- The portal password is written twice (`changePassword`, then `logoutOtherDevices` rehashes it). If the second write fails, other sessions stay signed in.
- `PortalPageRequest` narrows `page` to `a4` on every portal page. Move the rule into the two print requests before any portal list uses `?page=N` for pagination.
- The admin SPA ignores `?from=portal`, so staff redirected from the portal don't see a note.
