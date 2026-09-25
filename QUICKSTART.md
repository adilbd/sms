# School Management System - Quick Start Guide

Everything runs from one Laravel app in `backend/`: the public website at `/`, the admin panel at `/admin` and the API at `/api`. For full details, see [README.md](README.md).

## 🚀 Starting the Application

### Option 1: Local (Recommended)

```bash
cd backend
composer install && npm install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite        # or set DB_* in .env to use MySQL
php artisan migrate:fresh --seed
composer dev                          # server + queue + logs + Vite
```

### Option 2: Setup Script (MySQL)
```bash
chmod +x setup.sh
./setup.sh
cd backend && composer dev
```

### Option 3: Docker
```bash
cp backend/.env.example backend/.env
docker compose up
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate:fresh --seed
```
With Docker, MySQL is reachable from the host on port **3307** (database `scms`, root password `password`).

## 🔐 Default Login Credentials

| URL | What |
|-----|------|
| http://localhost:8000 | Public website |
| http://localhost:8000/admin | Admin panel |

Log in to the admin panel with:

- **Email:** admin@sms.com
- **Password:** password

## 📚 Available Modules

### Working Now
- ✅ **Public website**: home, about, admissions, contact, news, events, sitemap
- ✅ **News & Events admin**: create, edit and publish posts
- ✅ **Student Management**: API CRUD and the admin list with filters
- ✅ **Class, Section, Subject and Academic Year** APIs
- ✅ **Attendance** API with bulk marking and reports
- ✅ **Public API** for the mobile app (`/api/public/*`)

### Not Built Yet
- ⚠️ Teacher, Parent, Exam and Fee APIs are stubs (`index` returns an empty list, everything else returns 501)
- ⚠️ The dashboard stats are placeholders, and the dashboard API endpoints aren't implemented yet
- ⚠️ Most admin views besides the Students list and Posts are placeholders

### User Roles & Permissions
- **Admin**: full system access
- **Teacher**: attendance, exam results, student management
- **Student**: view own attendance, exams, results
- **Parent**: view children's data, attendance, fees

## 🔌 API Endpoints

### Authentication
```
POST   /api/login
POST   /api/logout
GET    /api/me
POST   /api/change-password
```

### Students
```
GET    /api/students
POST   /api/students
GET    /api/students/{id}
PUT    /api/students/{id}
DELETE /api/students/{id}
```

### Attendance
```
GET    /api/attendances
POST   /api/attendances
POST   /api/attendances/bulk
GET    /api/attendances/report/{student}
```

### Public (no auth)
```
GET    /api/public/school
GET    /api/public/news            GET /api/public/news/{slug}
GET    /api/public/events          GET /api/public/events/{slug}
POST   /api/public/contact
```

### Other Modules
- Classes: `/api/classes`
- Sections: `/api/sections`
- Subjects: `/api/subjects`
- Academic years: `/api/academic-years`
- Posts (admin): `/api/posts`
- Teachers, Parents, Exams, Fees: stubs

## 🎨 Routes

### Public Website (Blade)
```
/                       - Home
/about                  - About
/admissions             - Admissions
/contact                - Contact form
/news, /news/{slug}     - News
/events, /events/{slug} - Events
/sitemap.xml, /robots.txt
```

### Admin Panel (Vue, all under /admin)
```
/admin/login                  - Login page
/admin                        - Dashboard
/admin/students               - Student list
/admin/students/create        - Add new student
/admin/students/:id           - Student details
/admin/teachers               - Teacher list
/admin/classes                - Class management
/admin/subjects               - Subject management
/admin/attendance             - Attendance list
/admin/attendance/mark        - Mark attendance
/admin/exams                  - Exam management
/admin/fees                   - Fee management
/admin/posts                  - News & events
/admin/posts/create           - New post
/admin/posts/:id/edit         - Edit post
/admin/profile                - User profile
```

## 🛠️ Development Commands

Run these from `backend/`:

```bash
# Dev servers
composer dev                 # everything at once
php artisan serve            # PHP only
npm run dev                  # Vite only (port 5173)

# Database
php artisan migrate
php artisan migrate:fresh --seed

# Scaffolding
php artisan make:controller Api/YourController
php artisan make:model YourModel -m

# Tests and code style
php artisan test
php artisan test --filter=PublicSeoTest
./vendor/bin/pint

# Production assets
npm run build

# Clear caches
php artisan optimize:clear
```

## 🐛 Troubleshooting

**Blank page, or a "Vite manifest not found" error:**
- Start Vite with `npm run dev`, or build the assets once with `npm run build`

**Port 8000 or 5173 already in use:**
```bash
lsof -ti:8000 | xargs kill -9
lsof -ti:5173 | xargs kill -9
```

**Database connection error:**
- Check `DB_CONNECTION` and the `DB_*` values in `backend/.env`
- SQLite: make sure `backend/database/database.sqlite` exists
- Docker MySQL: the compose file sets the connection. Inside the containers the host is `mysql`; from your machine it's on port 3307.

**Admin keeps redirecting to /admin/login:**
- Your token is missing or has expired. Log in again. If the problem continues, clear `localStorage` for the site.

**Logs:** `backend/storage/logs/laravel.log`, or `php artisan pail`

## 🔒 Security Notes

- Change the default admin password immediately
- Generate a unique `APP_KEY` for each environment
- Never commit `.env` to version control
- Enable HTTPS in production

## 📝 Next Steps

1. Start the app and log in to `/admin`
2. Create an academic year, classes, sections and subjects
3. Register students and mark attendance
4. Publish news and events, then check them on the public site
5. See [STATUS.md](STATUS.md) for what still needs to be built
