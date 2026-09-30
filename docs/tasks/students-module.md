# Build the Students module (guardians, logins, yearly enrolment)

Status: done
Branch: feat/students-module

## Problem
The next modules (Exams/GPA, Fees, Attendance) need students, and the Students code is still legacy:

- `Api\StudentController` validates input and queries the database inside the controller. It catches exceptions and returns `$e->getMessage()` with a 500. Its responses use the old shapes.
- Addresses are Indian (`state`, `pincode`, `caste`, `country` defaulting to India).
- There is no group and no 4th subject, and each student has a single class and section with no history across years.
- The `parents` / `parent_student` tables, `ParentModel` and the `/api/parents` stub have never been used.
- Login requires an email address, which many Bangladeshi students and guardians don't have.
- The parent role has `view-students`, so a guardian could list every student.

The intended outcome:
- Students have a Bangladeshi profile, with the guardian's details on the student.
- Every student gets a login. Each guardian gets one login, shared across siblings.
- Each student has one enrolment per academic year: section, roll number, group from Class 9, and a 4th subject from the curriculum.
- Students and guardians can read only their own records.

Promoting a class to the next year is Task B (`feat/student-promotion`). The full design is in the approved plan.

## Scope
**In:**

1. **Migrations**
   - Drop the old data and tables.
     - Delete users with the `student` or `parent` role, plus their tokens and role rows.
     - Delete the rows that point at students in `attendances`, `exam_results` and `fee_payments`.
     - Drop those tables' `student_id` foreign keys, then drop `parent_student`, `parents` and `students`.
     - `down()` recreates the old tables' structure without data, and a comment says so.
   - `users`: add `username` (nullable, unique) and make `email` nullable.
   - Create a new `students` table (soft deletes):

     | Area | Columns |
     |---|---|
     | Identity | `student_id` (unique, auto-generated as `{admission year}{4-digit sequence}`, e.g. `20260001`; also the login username) |
     | Names | `name_en`, `name_bn` (at least one required, checked in the service) |
     | Personal | `date_of_birth`, `gender` (male/female/other), `religion` (islam/hinduism/buddhism/christianity/other), `blood_group`, `birth_registration_number` (nullable, unique, 17 digits), `nationality` (default `Bangladeshi`) |
     | Contact | `mobile`, `email` (both nullable) |
     | Address | `present_address`, `permanent_address`, `district` |
     | Photo | `photo` (public disk, `students/{uuid}.{ext}`) |
     | Status | `admission_date`, `status` (active/left/graduated), `leaving_date` |
     | Father | `father_name_en/bn`, `father_mobile`, `father_occupation` |
     | Mother | `mother_name_en/bn`, `mother_mobile`, `mother_occupation` |
     | Guardian | `guardian_relation` (father/mother/other), `guardian_name`, `guardian_mobile` (required), `guardian_user_id` (FK users, `nullOnDelete`) |
     | Student login | `user_id` (FK users, `nullOnDelete`) |

   - Re-add the `student_id` foreign keys on `attendances`, `exam_results` and `fee_payments` (cascade on delete).
   - Create `student_enrolments`:
     - `student_id` (cascade on delete)
     - `academic_year_id`, `class_id` and `section_id` (restrict on delete; `class_id` is taken from the section)
     - `group` (nullable)
     - `optional_subject_id` (nullable, FK subjects, restrict on delete)
     - `roll_number` (nullable)
     - `status` (active/promoted/retained/left/graduated, default active)
     - timestamps
     - unique on `(student_id, academic_year_id)`

2. **Enrolment rules** (`EnrolmentService`)
   - The section must be active and on an active shift.
   - A group is required from Class 9 and forbidden below it. It must match the section's group when the section has one.
   - A 4th subject is forbidden below Class 9. From Class 9 it must be an `optional` curriculum row for that class, either with no group or with the student's group. This reuses the curriculum repository.
   - Roll numbers are unique per section per year. This is checked in the service, because the column is nullable.
   - The section's capacity can't be exceeded. The section row is locked in the same transaction.

3. **Accounts** (`StudentService`)
   - Creating a student also creates a `student` user in one transaction. The username is the `student_id`, the password is set by the admin (at least 8 characters) and email is optional.
   - The guardian login is shared by guardian mobile. If a `parent` user's `username` already equals `guardian_mobile`, the student is linked to it. Otherwise a new parent user is created with `guardian_password`, which is required only in that case. If the mobile belongs to a user who isn't a parent, return 422.
   - Changing `guardian_mobile` relinks the student and deactivates the old guardian login if it has no active children left.
   - `password` and `guardian_password` are optional on update, and reset the matching login when given.
   - Mobiles are normalized to `01XXXXXXXXX` (`+88` stripped) in `prepareForValidation`.
   - A non-active `status` requires a `leaving_date`, and the leaving date must be on or after the admission date.
   - Deleting a student returns 409 while it has attendances, exam results or fee payments. Otherwise it soft-deletes the student, deactivates the student login, and deactivates the guardian login if it has no active children left. Enrolments are kept.

4. **Existing guards**
   - `hasStudents()` on classes, sections and academic years now checks `student_enrolments`.
   - `SubjectService::delete` returns 409 while an enrolment uses the subject as its 4th subject.

5. **Auth conversion.** This legacy module is converted in full.
   - `AuthService`, plus `UserRepositoryInterface`/`UserRepository` with `findForLogin()`, which looks the user up by email, username or normalized mobile.
   - `LoginRequest` takes `login`, and still accepts `email` as an alias. `ChangePasswordRequest` covers password changes.
   - `UserResource` returns `id`, `name`, `email`, `username`, `phone`, `is_active` and `roles`/`permissions` as names.
   - Responses:
     - login returns `{data: {token, user}}`
     - me returns `{data: user}`
     - logout and change-password return `{message}`
   - Update the SPA: `stores/auth.js`, `Login.vue` (the field becomes "Email, student ID or mobile") and the consumers in `Profile.vue`.

6. **Students API**
   - Thin `StudentController` using `resourcePermissions('students')`, with a numeric id constraint on its routes.
   - `index` filters on `academic_year_id` (defaults to the active year), `class_id`, `section_id`, `shift_id`, `group`, `status` and `search`. Search matches names, `student_id`, `guardian_mobile` and the birth registration number, grouped in a nested `where`.
   - Store and update take the profile, guardian details and passwords, plus `enrolment` (`section_id`, `group`, `optional_subject_id`, `roll_number`) for the active year. Everything is written in one transaction.
   - `StudentResource` has the profile, `username`, the guardian block and `photo_url`, plus `current_enrolment` and `enrolments` via `whenLoaded`.
   - `GET /api/students/{student}/enrolments` (`view-students`).

7. **Own-records endpoints**
   - `GET /api/my/student` (`role:student`) returns the signed-in student's own record.
   - `GET /api/my/children` (`role:parent`) returns the guardian's linked children.
   - Both go through `StudentService`.

8. **Permissions**
   - Remove `view-students` from the parent role.
   - Make `RolePermissionSeeder` safe to re-run (`findOrCreate`/`firstOrCreate`).
   - Update `ApiAuthorizationTest`.

9. **Remove the Parents stub**
   - Delete `ParentModel`, `ParentController` and the `/api/parents` route.
   - Replace `User::parent()` with `User::children()`.
   - Remove any Parents admin link or view.
   - Update `CLAUDE.md` and the docs.

10. **Admin SPA**
    - `StudentList.vue`: filters, `meta` pagination, and columns for student ID, names, class/section/roll, group and guardian mobile.
    - `StudentForm.vue`: sections for Profile, Father/Mother, Guardian (with a "same as father/mother" helper), Enrolment and Login.
      - In Enrolment, the group and 4th subject appear from Class 9. The 4th-subject list comes from `/api/classes/{id}/subjects?group=`.
      - In Login, the guardian password field appears only when the guardian mobile is new, and the 422 tells the form when it's required.
      - It has a photo upload and per-field 422 and 409 messages.
    - `StudentDetails.vue`: profile, guardian, username and enrolment history.

11. **`StudentSeeder`**, run after `CurriculumSeeder`
    - 5 demo students per class in 2026, all in Section A of the Morning shift.
    - From Class 9 each student has a group and a valid 4th subject.
    - Some students share a guardian mobile, so there are siblings.
    - All passwords are `password`. It matches on `student_id`, so re-running it adds nothing.

12. **Docs**
    - Add a Students note to `CLAUDE.md`.
    - Remove `StudentController`, `AuthController`, `login`/`me` and the parents stub from the legacy tables in both guidelines.

**Out:**
- Promotion to the next year (Task B).
- A student/guardian portal UI.
- Password reset by SMS.
- Bulk import.
- Attendance, Exams and Fees built on enrolments.
- Limiting teachers to their own sections.

## Guidelines that apply
- `docs/architecture-guidelines.md`: Students and Auth are both legacy modules, and each is converted in full in this change.
- `docs/api-response-guidelines.md`: the legacy students, login and me endpoints move to the standard shapes, and their SPA consumers are updated in the same change.
- `CLAUDE.md` domain rules: Class 1–12, groups from Class 9, Bangla names, Asia/Dhaka for date-only defaults, and "Class", not "Grade".

## Acceptance criteria
- [ ] The migrations run on SQLite and MySQL, including rollback. The old student and parent data and tables are gone. SQLite, rollback and data deletion are verified. MySQL hasn't been run yet; `php artisan migrate` on Docker will delete that database's student and guardian data by design.
- [x] `StudentController` and `AuthController` are thin. They contain no Eloquent, no `DB::` calls, no inline validation and no try/catch.
- [x] Every students and auth endpoint returns the guideline shapes, and the SPA reads them.
- [x] Every enrolment rule and account rule above is enforced, with errors keyed to the field (`enrolment.group`, and so on).
- [x] Login works with an email, a student ID or a guardian mobile. Siblings share one guardian login.
- [x] `/api/my/student` and `/api/my/children` return only the caller's own records. A parent gets 403 on `/api/students`.
- [x] The Parents stub is fully removed.
- [x] `RolePermissionSeeder` can be run twice.
- [x] `migrate:fresh --seed` seeds demo students with enrolments, and a second seed run adds nothing.
- [x] The full suite, Pint on the changed files, `npm run build` and the smoke script all pass.
- [x] `CLAUDE.md` and both guidelines are updated.

## Test cases
- [x] **Happy path.**
  - Creating a Class 9 Science student returns 201 with an auto `student_id`, a student login and a guardian login. The response has `data.current_enrolment.group` and `data.username`.
  - The student list defaults to the active year and has `meta.total`.
  - Showing a student returns their enrolment history.
  - Updating a student with a new password resets their login.
  - Deleting a plain student returns 204 and deactivates both logins.
- [x] **Validation (422):**
  - neither name is given
  - a group below Class 9
  - no group from Class 9
  - a group that doesn't match the section's group
  - a 4th subject that isn't an optional choice for that class and group
  - a 4th subject below Class 9
  - a duplicate roll number in the same section and year
  - the section is full
  - an inactive section or shift
  - a bad mobile number
  - a duplicate birth registration number
  - a non-active status without `leaving_date`
  - a `leaving_date` before `admission_date`
  - a new guardian mobile without `guardian_password`
  - a guardian mobile that belongs to a non-parent user
  - `?search[]=x`
- [x] **Guardian login.**
  - Two students with the same guardian mobile share one parent user.
  - Changing a student's guardian mobile relinks them, and the old guardian login is deactivated once it has no active children left.
- [x] **Auth.**
  - Login succeeds with an email, with a student ID, and with a guardian mobile given as `+8801…`.
  - The `email` alias still works.
  - A wrong password and an inactive account both return 422.
  - Response shapes: login `{data: {token, user}}`, me `{data: user}`. Logout and change-password work.
  - The admin can still log in to `/admin`.
- [x] **Authorization.**
  - A guest gets 401.
  - A teacher can read students but gets 403 on writes.
  - A parent gets 403 on `/api/students`.
  - A student sees only their own record at `/api/my/student`, and a guardian sees only their own children at `/api/my/children`.
  - Each of those roles gets 403 on the other role's endpoint.
- [x] **Conflicts (409).**
  - Deleting a student who has attendance.
  - Deleting a class, section or year that has enrolments.
  - Deleting a subject that an enrolment uses as its 4th subject.
- [x] **Edge cases.**
  - `/api/students/1abc` returns 404.
  - A concurrent duplicate `student_id` or roll number returns 422, not 500.
  - Running `RolePermissionSeeder` and `StudentSeeder` twice creates no duplicates.
- [x] **Unit tests.** `StudentServiceTest`, `EnrolmentServiceTest` and `AuthServiceTest` cover each rule with mocked repository interfaces. The class, section and year guard tests are updated to use enrolments.
