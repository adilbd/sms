# Replace the dashboard stub with real data for each role

Status: done
Branch: feat/dashboard

## Problem
The dashboard is still a stub. `DashboardController` returns `{data: []}`, and the `dashboard/stats` and `dashboard/recent-activities` routes point at methods that don't exist, so they return 500. The admin `Dashboard.vue` shows hardcoded placeholder numbers ("1250 students", "92.5%", "John Doe"), the same for every role, with admin-only quick actions even for teachers.

Every module the dashboard needs now exists: students and enrolments, staff and their logins, attendance, exams and results, and fees. The intended outcome is one endpoint that returns real, role-appropriate numbers, and a dashboard screen built from them:
- **Admin:** the whole school.
- **Teacher:** their own sections and mark sheets.
- **Office:** collections.

This is Task 6 of 7 in `/Users/adil/.claude/plans/make-the-plan-for-radiant-flurry.md`.

## Scope
**In:**

1. **`GET /api/dashboard`.** Any signed-in staff role can call it. Students and parents get 403. Map it in `ApiAuthorizationTest`, either through a role middleware for admin, teacher and office, or with a documented open entry and a 403 from the service. The response is computed data under `data`, with named keys. The service picks the sections by the caller's role. A user with several roles gets the widest view: admin > office > teacher. All figures are for the active academic year, and "today" is today in Asia/Dhaka.
   - **Admin:**
     - **`counts`:** active students in total, by level (primary / junior_secondary / secondary / higher_secondary), by group (Class 9+) and by shift; active staff, teachers and staff with a login; active sections.
     - **`attendance_today`:** whether today is a school day (`is_school_day`, a holiday name, a weekly holiday); sections marked and not marked; present % overall; per section `{section, marked, present, absent, late, leave, percentage}`.
     - **`exams`:** the latest non-draft exam `{id, name, status}`, plus per class `{class, students, passed, pass_rate, top_gpa}` when results exist, and mark sheets still incomplete for exams in marks_entry.
     - **`fees`:** this month's dues (net), collected and outstanding; today's collection in total and by method; overdue dues, meaning past `due_date` and not paid.
     - **`recent`:** the last 5 payments (receipt number, student, amount, method, time), the last exams processed or published, and the last 5 students admitted.
   - **Teacher** (through `TeacherScope`):
     - their class-teacher sections' attendance today (marked or not, with present %);
     - for open exams, their own subject and section mark sheets with entered and total counts;
     - their class-teacher sections' latest pass rate.
   - **Office:** today's collection (total and by method), this month's collected and outstanding, the last 10 receipts, and a count of students with outstanding dues.
   - Money is sent as `decimal:2` strings and percentages as `decimal:2` strings.
2. **`DashboardService` and `DashboardRepository`** (with an interface, bound in `RepositoryServiceProvider`).
   - Use aggregate queries: `count`, `sum` and `group by`. Don't loop with N+1 queries.
   - Reuse what's already there: `SchoolDays` / `InstituteSettingsService::weeklyHolidays()` and holidays for today, `TeacherScope`, `Money`, and the exam and fee status constants.
   - A feature test asserts that the query count is bounded and doesn't grow with the number of students or sections. For example, seed 2 sections and then 6, and get the same query count.
3. **Remove the stub.** Remove the `dashboard/stats` and `dashboard/recent-activities` routes. Replace the `DashboardController` stub with a thin controller.
4. **SPA.** Rebuild `views/Dashboard.vue` for each role from the response.
   - Stat cards; an attendance-today table, or bar list of present % per section, with a "not marked yet" list; exam pass rate per class; and fee totals with a by-method breakdown.
   - Quick actions follow the role's permissions. A teacher sees "Mark attendance" and "Enter marks" only; office sees "Collect fee".
   - Charts are simple CSS or SVG bars (no new chart library). Follow the `dataviz` skill guidance for colours and labelling, and keep them accessible: text values next to the bars.
   - Show empty states: a holiday, no exam yet, no fees yet.
5. **Docs.**
   - Add a "Dashboard" note to CLAUDE.md.
   - Remove Dashboard from the stub lists in CLAUDE.md and both guidelines. After this task, no stubs are left.

**Out:**
- Historical trends or charts over time.
- Exporting.
- Notifications.

## Acceptance criteria
- [x] `GET /api/dashboard` returns the documented shape for admin, teacher and office. Students and parents get 403, and guests get 401.
- [x] The numbers are correct on seeded data, and teachers only see their own sections.
- [x] The query count is bounded: the same with 2 or 6 sections.
- [x] Stub routes are removed, and the old `dashboard/*` routes return 404.
- [ ] `Dashboard.vue` shows real data per role, with permission-aware quick actions and empty states. The build passes. A browser check on Docker happens before the merge.
- [x] The full suite, Pint, `npm run build` and smoke pass. CLAUDE.md and both guidelines are updated, with no stubs left listed.

## Test cases
- [x] **Admin.**
  - Counts by level, group and shift match the seeded enrolments.
  - Attendance today: one section marked with 4 present and 1 absent gives 80.00%, and an unmarked section is listed.
  - On a Friday or a holiday, `is_school_day` is false and the reason is given.
  - Exams: the latest exam's pass rate and top GPA per class.
  - Fees: this month's net, collected and outstanding; today's collection by method; and overdue dues, all as `decimal:2`.
  - Recent payments and admissions are in newest-first order.
- [x] **Teacher.**
  - Only their class-teacher sections appear in attendance.
  - Only their own mark sheets appear, with entered and total counts.
  - A teacher with nothing assigned gets empty sections and no 500.
- [x] **Office:** today's collection and the recent receipts. No exam or attendance blocks.
- [x] **Multiple roles:** a user who is both admin and teacher gets the admin shape.
- [x] **Authorization:** a student or parent gets 403, and a guest gets 401.
- [x] **Performance:** the query count with 6 sections equals the query count with 2.
- [x] **Legacy:** `/api/dashboard/stats` and `/api/dashboard/recent-activities` return 404.
- [x] **Unit:** `DashboardServiceTest` covers role selection and the percentage and money formatting, with mocked repositories.
