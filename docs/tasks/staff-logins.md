# Give staff logins and limit teachers to their own work

Status: done
Branch: feat/staff-logins

## Problem
Staff can't sign in. Marks may only be entered by the assigned subject teacher or an admin, so in practice only admins can enter them. Attendance (Task 2) and fee collection (Task 5) will also need staff logins.

There is a second problem. The teacher role's permissions are role-wide: a teacher would see every student in the school, not just the ones they teach.

The intended outcome:
- Any active staff member can be given a login. Teachers get the `teacher` role. Non-teaching staff get a new `office` role.
- A signed-in teacher sees only their own work. The server enforces this, and the admin sidebar matches.

This is Task 1 of 7 in `/Users/adil/.claude/plans/make-the-plan-for-radiant-flurry.md`.

## Scope
**In:**

1. **Accounts**, in `StaffService` with the user writes going through `UserRepositoryInterface`:
   - The Staff create and update payload gains a `login` block: `{enabled: bool, role: teacher|office, password?, email?}`.
   - The role must match the category: `teacher` only for `category=teacher`, `office` only for `category=staff`. Otherwise 422.
   - **Turning a login on:**
     - Requires a non-empty `employee_id` (422 on `employee_id`).
     - Creates or links a user with username = `employee_id` (lowercased the same way as `findForLogin`), `name` from `name_en`/`name_bn`, and an optional email (lowercased, unique).
     - A password is required when the user is created (min 8). On a later update it is optional and resets the password when given.
     - Assigns the role.
     - Returns 422 if that username or email already belongs to a different user, or if the staff member's linked user is a student or parent.
   - **Changes to `employee_id`** update the username.
   - **Turning a login off, or a non-active staff status,** deactivates the user and revokes all their tokens. Turning it on again, or moving back to active, reactivates the user.
   - **Deleting a staff member** deactivates their login.
   - Everything runs in one transaction with the staff write.
   - `StaffResource` (admin shape) gains `login: {enabled, username, email, role, is_active}`. It is never included in the public or narrow shapes.
2. **The `office` role**, in `RolePermissionSeeder`, which stays re-runnable:
   - `view-students`, `create-students`, `edit-students`
   - `view-fees`, `create-fees`, `collect-fees`
   - `view-classes`, `view-subjects`
   - no `delete-*`, no settings permissions, no exam permissions
3. **Teacher scoping on the server:**
   - **`App\Services\TeacherScope`:**
     - `forUser(User): ?TeacherContext` returns the staff row, the subject assignments for a year (default active), the section ids the teacher teaches, and the section ids where they are class teacher.
     - It reuses `SubjectAssignmentRepository` and `ClassTeacherRepository` methods. Add intent-named methods if needed.
   - **`GET /api/my/assignments`** (`role:teacher`, optional `academic_year_id`) returns `{data: {academic_year, subjects: [{section, subject, class}], class_teacher_of: [section]}}`.
   - **Students:**
     - For a user with the `teacher` role who isn't an admin, `StudentController@index` adds a server-built `scope_section_ids` filter: the sections they teach or lead in the listed year. It is never taken from input.
     - `show` and `enrolments` return 403 for a student outside that scope.
     - Teachers keep the narrow non-sensitive `StudentResource`.
   - **Mark sheets:**
     - The exam subjects list (`GET /api/exams/{exam}/subjects`) filters to the teacher's assigned subjects when the caller is a teacher.
     - The section picker data (wherever the SPA gets it for mark entry) is limited to the teacher's assigned sections. `canEnterMarks` already guards reads and saves.
4. **SPA:**
   - **Sidebar:** `Layout.vue` builds the sidebar from `authStore` roles and permissions.
     - Teachers see: Dashboard, My subjects (new `views/teacher/MySubjects.vue`, reading `/api/my/assignments`), Exams → Mark entry only, and Students (read-only list scoped by the server).
     - Office staff see: Dashboard, Students, Fees, and Classes and Subjects read-only.
     - Admin is unchanged.
   - **Router:** each route's `meta` declares the permission or role it needs. A navigation guard sends users without it to the dashboard.
   - **Page titles:** route meta titles also fix the "StudentPromotion" header.
   - **Staff form:** `StaffForm.vue` gains a Login section (toggle, role, password, email), with 422 errors shown per field.
5. **Seeder:** `StaffSeeder` doesn't create logins. Add a `StaffLoginSeeder`, run from `DatabaseSeeder` after `StaffSeeder`, that gives two demo teachers and one office staff member a login with password `password`. It matches on `employee_id`, so re-running it adds nothing.
6. **Docs:** add a "Staff logins and teacher scope" note to CLAUDE.md.

**Out:**
- Attendance UI (Task 2).
- Fees UI (Task 5).
- Teacher dashboard data (Task 6).
- Self-service password reset.

## Guidelines that apply
- `docs/architecture-guidelines.md`: services and repositories. Record-scoped rules live in the service, and the controller builds the scope from the user.
- `docs/api-response-guidelines.md`: `data` shapes and 403/422.
- CLAUDE.md: Students and Auth note (login, throttle, token revocation), Staff and Subject teachers notes.

## Acceptance criteria
- [x] An admin can give a staff member a login. The staff member can sign in with their employee ID or email. Reset, revoke and leaving all work, and tokens are revoked on deactivation.
- [x] The office role exists with exactly the listed permissions.
- [x] Teachers only see students in their own sections. Other students return 403.
- [x] `/api/my/assignments` returns the teacher's subjects and class-teacher sections.
- [x] A teacher's mark-entry pickers only list their own subjects and sections.
- [x] The SPA sidebar and route guards match each role, and page titles come from route meta.
- [x] `ApiAuthorizationTest`, the full suite, Pint, `npm run build` and smoke all pass. CLAUDE.md is updated.

## Test cases
- [x] **Happy path.**
  - Enabling a login for a teacher with `employee_id` `VHBUB-12` creates a user with username `vhbub-12` (or whatever normalisation `findForLogin` applies) and the teacher role.
  - Logging in with `VHBUB-12` returns 200, and so does logging in with the email.
  - A password reset on update works.
- [x] **Validation (422):**
  - no `employee_id`
  - the `office` role for a teacher
  - the `teacher` role for non-teaching staff
  - a duplicate email
  - an `employee_id` that is already another user's username
  - no password on the first enable
  - linking a staff member to a student or parent user
- [x] **Deactivation.** Disabling a login, setting status to retired, or deleting the staff member each deactivates the user and revokes their tokens. The next API call gets 401. Re-enabling restores access.
- [x] **Office role.**
  - Can view students and collect fees. The fee endpoints are still stubs, so check this at the permission level.
  - Gets 403 on `DELETE /api/students/{id}`, `PUT /api/settings/institute` and `/api/exams` writes.
- [x] **Teacher scope.**
  - The teacher assigned to 9-A Physics lists students: only 9-A appears.
  - Showing a 6-A student returns 403.
  - A class teacher of 7-A also sees 7-A.
  - Sending `?section_id=` for a section outside their scope returns an empty list or 403. The scope can't be widened from input.
  - An admin still sees everyone.
- [x] **`/api/my/assignments`.** Returns the right subjects and sections. A non-teacher gets 403. A teacher with no staff link gets an empty data block or 403; document which.
- [x] **Mark sheets.** A teacher's `GET /api/exams/{exam}/subjects` lists only their assigned subjects. The mark sheet for an unassigned subject returns 403.
- [x] **Seeder.** `StaffLoginSeeder` run twice creates no duplicates.
- [x] **Unit tests.** `StaffServiceTest` covers the login rules with mocks. `TeacherScopeTest` covers the scope resolution.

## Browser check (Docker MySQL)
- [x] Migrating added `login_enabled`, and the seeder created the `vhbub-3` and `vhbub-9` teacher logins and the `vhbub-52` office login.
- [x] `VHBUB-3` signs in through the admin login form. The sidebar shows only Dashboard, My subjects, Students and Mark entry.
- [x] After assigning `VHBUB-3` Bangla 1st Paper in 10-A, the teacher's student list shows only the 5 students in 10-A. Opening a Class 6 student returns 403.
- [x] Opening `/admin/institute` directly as a teacher redirects to the Dashboard. There are no console errors.
- Follow-up for Task 3: the admin router has no catch-all, so an unknown `/admin/...` path renders blank. This was already the case on `main`.
