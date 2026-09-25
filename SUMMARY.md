# School Management System - Project Summary

A School Management System built as **one Laravel 11 application** (`backend/`). The same app serves a public, SEO-friendly school website, a Vue 3 admin panel and a JSON API.

---

## 📦 What Was Built

### 🌐 Public Website (Blade, server-rendered)
- Pages: home, about, admissions, contact, news, events (with detail pages)
- A shared `<x-seo>` component for title, description, canonical URL, Open Graph/Twitter tags and JSON-LD (`App\Support\Seo`, `App\Support\SchemaOrg`)
- `sitemap.xml` (cached, cleared whenever a post changes) and `robots.txt`
- Contact form, rate-limited and stored in `contact_messages`
- Site and organization details configured in `config/seo.php` via `SEO_*` / `SCHOOL_*` env vars

### 🗄️ Database
- **User Management**: users, roles, permissions (via Spatie), personal_access_tokens
- **Academic**: academic_years, classes, sections, class_sections, subjects, subject_assignments
- **People**: students, teachers, parents, parent_student
- **Attendance**: attendances
- **Examinations**: exams, exam_schedules, exam_results
- **Finance**: fee_types, fee_structures, fee_payments
- **Public content**: posts (news and events), contact_messages
- **System**: cache, jobs

**Seeded with:**
- Admin user (admin@sms.com / password)
- 4 roles (admin, teacher, student, parent) and 40+ permissions
- Sample news and events for the public site (`PublicContentSeeder`)

### 🔧 API (Laravel 11)

**Authentication & Authorization:**
- Laravel Sanctum bearer tokens
- Spatie Laravel Permission (`role` / `permission` middleware)

**API Controllers:**
- AuthController ✅ Login, logout, me, change password
- StudentController ✅ Full CRUD
- ClassController ✅ Full CRUD
- SectionController ✅ Full CRUD
- SubjectController ✅ Full CRUD
- AcademicYearController ✅ Full CRUD + activate
- AttendanceController ✅ CRUD + bulk marking + reports
- PostController ✅ News & events CRUD (admin only)
- PublicContentController ✅ Unauthenticated `/api/public/*` for the mobile app
- TeacherController ⚠️ Stub
- ParentController ⚠️ Stub
- ExamController ⚠️ Stub
- ExamScheduleController ⚠️ Stub
- ExamResultController ⚠️ Stub
- FeeTypeController ⚠️ Stub
- FeeStructureController ⚠️ Stub
- FeePaymentController ⚠️ Stub
- DashboardController ⚠️ Stub (its `stats` / `recent-activities` routes have no methods yet)

The website and the public API read posts through the same `PostService`, so they always show the same content.

### 🎨 Admin Panel (Vue 3, served at `/admin`)

**Tech Stack:**
- Vue 3 with Composition API
- Vue Router 4 (history base `/admin/`) with auth guards
- Pinia for state management
- Tailwind CSS
- Axios with a bearer-token interceptor
- Vite 6 through `laravel-vite-plugin`, source in `backend/resources/js/admin`

**Views:**
- Login ✅ With form validation
- Layout ✅ Responsive sidebar navigation
- Dashboard ✅ Layout with stat cards (the numbers are placeholders)
- StudentList ✅ With filters, search, pagination
- PostList / PostForm ✅ Manage news & events
- StudentForm ⚠️ Placeholder
- StudentDetails ⚠️ Placeholder
- TeacherList ⚠️ Placeholder
- ClassList ⚠️ Placeholder
- SubjectList ⚠️ Placeholder
- AttendanceList ⚠️ Placeholder
- MarkAttendance ⚠️ Placeholder
- ExamList ⚠️ Placeholder
- FeeList ⚠️ Placeholder
- Profile ⚠️ Placeholder

### 🧪 Tests
- `PublicSeoTest`: every public page is server-rendered, has a complete SEO head and exactly one `<h1>`, and unknown or draft posts return a noindex 404
- `PublicApiTest`: the public mobile-app API

---

## 🚀 How to Use

```bash
cd backend
composer install && npm install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite
php artisan migrate:fresh --seed
composer dev
```

- Public website: **http://localhost:8000**
- Admin panel: **http://localhost:8000/admin** (admin@sms.com / password)

The Docker and MySQL setup is covered in [README.md](README.md).

---

## 📁 Project Structure

```
sms/
├── backend/                          # The Laravel app
│   ├── app/
│   │   ├── Http/Controllers/Api/     # JSON API
│   │   ├── Http/Controllers/Web/     # Public website
│   │   ├── Http/Resources/
│   │   ├── Models/
│   │   ├── Services/                 # PostService, ContactService
│   │   └── Support/                  # Seo, SchemaOrg
│   ├── config/seo.php
│   ├── database/{migrations,seeders}
│   ├── resources/
│   │   ├── css/public.css            # Public site styles
│   │   ├── js/admin/                 # Admin Vue SPA
│   │   └── views/                    # Blade views
│   ├── routes/{web,api}.php
│   └── tests/Feature/
├── docker/ + docker-compose.yml      # MySQL + app + Vite
├── README.md
├── QUICKSTART.md
├── STATUS.md
└── setup.sh
```

---

## 🎯 What's Next?

### Immediate Priorities

1. **Implement the stub controllers**: teachers, parents, exams, fees, dashboard
2. **Build the placeholder admin views** with forms and validation
3. **Add file upload** for photos and documents
4. **Implement report generation** (PDF/Excel)
5. **Add email notifications**
6. **Create timetable management**
7. **Build the parent portal**
8. **Expand tests** beyond the public site and API

### Future Enhancements

- Library management module
- Transport management
- Hostel management
- HR & Payroll system
- Online examination system
- Mobile app (React Native) using `/api/public`
- SMS/WhatsApp integration
- Advanced analytics & reports

---

## 🛠️ Technologies Used

### Backend
- PHP 8.2+
- Laravel 11.x
- MySQL 8.0 (SQLite for local development and tests)
- Laravel Sanctum
- Spatie Laravel Permission
- Laravel Pint

### Frontend (bundled inside the Laravel app)
- Node.js 18+
- Vue 3, Vue Router 4, Pinia
- Tailwind CSS 3
- Vite 6
- Axios

---

## 🐛 Known Issues

1. Teacher, parent, exam, fee and dashboard controllers are stubs (empty lists or `501`). Some of their extra routes (dashboard stats, exam publish, fee receipts) have no methods yet and return server errors.
2. Most admin views besides Students list and Posts are placeholders
3. File upload not yet implemented
4. Report generation pending
5. Email notifications not configured

---

## 🤝 Contributing

To continue development:

1. Pick a feature from STATUS.md
2. Implement the API controller in `backend/app/Http/Controllers/Api`
3. Build the admin view in `backend/resources/js/admin/views`
4. Add validation and tests
5. Update the documentation

---

## 📝 License

MIT License - free to use and modify
