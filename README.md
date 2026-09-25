# School Management System (SMS)

A School Management System built as a single Laravel 11 application. It serves three things from one codebase:

| Path | What | Built with |
|------|------|------------|
| `/` | Public school website (home, about, admissions, contact, news, events) | Server-rendered Blade, SEO-ready |
| `/admin` | Admin panel | Vue 3 SPA, bundled by Vite |
| `/api` | JSON API for the admin panel and the mobile app | Laravel + Sanctum |

## Features

### Public Website
- Server-rendered pages with a full SEO head: title, description, canonical URL, Open Graph/Twitter tags, JSON-LD
- News and events with slugs and pagination
- `sitemap.xml` (cached for one hour and cleared whenever a post changes) and `robots.txt`
- Contact form (rate-limited, saved to the database)

### Admin Modules
- **Student Management**: admission, enrollment and tracking
- **Class & Section Management**: classes with nested sections
- **Subject Management**
- **Academic Year Management**: several academic years, with one marked active
- **Attendance Management**: daily attendance, bulk marking, per-student reports
- **News & Events**: create and publish posts for the public website (admin only)
- **Teacher, Parent, Exam and Fee Management**: routes and data model exist, but the API is still a stub (see [STATUS.md](STATUS.md))

### Public API (for the mobile app)
Unauthenticated, rate-limited endpoints under `/api/public`. They return the same school info, news and events as the website and accept contact submissions.

### User Roles & Permissions
- **Admin**: full system access
- **Teacher**: student management, attendance, exam results
- **Student**: view attendance, exams, results
- **Parent**: view children's attendance, exams, fees

## Tech Stack

- PHP 8.2+, Laravel 11
- MySQL 8 (SQLite also works for local development and is used by the tests)
- Laravel Sanctum (API token authentication)
- Spatie Laravel Permission (roles and permissions)
- Vue 3 (Composition API), Vue Router 4, Pinia, Axios
- Tailwind CSS 3, Vite 6 (`laravel-vite-plugin`)

## Project Structure

```
sms/
├── backend/                          # The Laravel app (everything lives here)
│   ├── app/
│   │   ├── Http/Controllers/Api/     # JSON API (admin + /api/public)
│   │   ├── Http/Controllers/Web/     # Public website controllers
│   │   ├── Http/Resources/           # API resources
│   │   ├── Models/
│   │   ├── Services/                 # Shared by the website and the API (posts, contact)
│   │   └── Support/                  # SEO and Schema.org helpers
│   ├── config/seo.php                # Site name, default meta, school organization details
│   ├── database/{migrations,seeders}
│   ├── resources/
│   │   ├── css/public.css            # Public website styles (Tailwind)
│   │   ├── js/admin/                 # Admin Vue SPA (router, stores, services, views)
│   │   └── views/                    # Blade: public pages, layouts, SEO component, admin shell
│   ├── routes/web.php                # Public pages + /admin catch-all
│   ├── routes/api.php                # API routes
│   └── tests/Feature/                # Public site SEO and public API tests
├── docker/                           # PHP image for docker compose
├── docker-compose.yml                # MySQL + app + Vite dev server
└── setup.sh                          # Interactive local setup script
```

## Installation

### Prerequisites
- PHP 8.2+ and Composer
- Node.js 18+ and npm
- MySQL 8.0+ (optional: `.env.example` defaults to SQLite)

### Local Setup

Run everything from `backend/`:

```bash
cd backend
composer install
npm install
cp .env.example .env
php artisan key:generate
```

Choose a database in `.env`:

- **SQLite** (the default): run `touch database/database.sqlite`
- **MySQL**: set the connection details, for example:

  ```env
  DB_CONNECTION=mysql
  DB_HOST=127.0.0.1
  DB_PORT=3306
  DB_DATABASE=sms_db
  DB_USERNAME=root
  DB_PASSWORD=your_password
  ```

Next, run the migrations and seed the database, then start everything:

```bash
php artisan migrate:fresh --seed
composer dev      # php artisan serve + queue worker + log tail + Vite, in one terminal
```

Once it's running:
- Public website: http://localhost:8000
- Admin panel: http://localhost:8000/admin
- API: http://localhost:8000/api

`composer dev` starts the Vite dev server on port 5173. Pages load their CSS and JS from it, so keep it running. Alternatively, run `npm run build` once and use `php artisan serve` on its own.

`./setup.sh` from the repo root does the same setup interactively for MySQL.

### Docker

`docker-compose.yml` sets the MySQL connection itself, so `backend/.env` only needs to exist:

```bash
cp backend/.env.example backend/.env
docker compose up
```

This starts:
- MySQL 8, reachable from the host on port **3307** (database `scms`, root password `password`)
- The app on http://localhost:8000
- The Vite dev server on port 5173

On the first run, create the tables and seed them:

```bash
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate:fresh --seed
```

## Default Credentials

After seeding, log in at `/admin/login` with:

- **Email**: admin@sms.com
- **Password**: password

## API Endpoints

The admin endpoints require a Sanctum bearer token, which you get from `POST /api/login`.

### Authentication
- `POST /api/login`: log in and receive a token
- `POST /api/logout`: log out
- `GET /api/me`: get the authenticated user
- `POST /api/change-password`: change password

### Resources (standard REST: index/store/show/update/destroy)
`students`, `classes`, `sections`, `subjects`, `teachers`, `parents`, `academic-years`, `attendances`, `exams`, `exam-schedules`, `exam-results`, `fee-types`, `fee-structures`, `fee-payments`, `posts` (admin role only)

The teacher, parent, exam and fee resources are stubs: `index` returns an empty list and the other actions return `501`.

### Extra Actions
- `POST /api/academic-years/{id}/activate`
- `POST /api/attendances/bulk`: bulk mark attendance
- `GET /api/attendances/report/{student}`

Routes exist for the following, but their controller methods aren't written yet, so calling them causes a server error:
- `POST /api/exams/{exam}/publish`
- `GET /api/exam-results/student/{student}/exam/{exam}`
- `GET /api/fee-payments/student/{student}`
- `GET /api/fee-payments/receipt/{feePayment}`
- `GET /api/dashboard/stats`
- `GET /api/dashboard/recent-activities`

### Public (no auth, rate-limited)
- `GET /api/public/school`
- `GET /api/public/news`
- `GET /api/public/news/{slug}`
- `GET /api/public/events`
- `GET /api/public/events/{slug}`
- `POST /api/public/contact`

## Database Schema

### Main Tables
- `users`: user accounts (plus the Spatie role/permission tables)
- `students`, `teachers`, `parents`, `parent_student`
- `academic_years`, `classes`, `sections`, `class_sections`, `subjects`, `subject_assignments`
- `attendances`
- `exams`, `exam_schedules`, `exam_results`
- `fee_types`, `fee_structures`, `fee_payments`
- `posts`: news and events for the public website
- `contact_messages`: contact form submissions

## Development

### Adding New Features

1. **Backend**: follow the Controller → Service → Repository pattern in [docs/architecture-guidelines.md](docs/architecture-guidelines.md). The Subjects module is the reference. Create:
   - a model and migration (`php artisan make:model YourModel -mf`)
   - a repository interface and its Eloquent implementation, bound in `RepositoryServiceProvider`
   - a service
   - FormRequests (`php artisan make:request YourModel/StoreYourModelRequest`)
   - an API Resource
   - a thin controller, with its routes in `routes/api.php`
2. **Admin UI**: add a view under `backend/resources/js/admin/views/` and a route in `backend/resources/js/admin/router/index.js`.
3. **Public page**: add a Blade view under `backend/resources/views/public/`. It extends `layouts.public`, fills the `seo` section with `<x-seo>`, and has exactly one `<h1>`.

### API Responses
All `/api` endpoints follow [docs/api-response-guidelines.md](docs/api-response-guidelines.md): the response shapes, error format, status codes and field conventions.

### Configuring the Public Site
Set the site name, default description and image, and school contact details in `.env` using the `SEO_*` and `SCHOOL_*` variables. See `backend/config/seo.php` for the full list.

### Running Tests and Code Style
```bash
cd backend
php artisan test                          # all tests (in-memory SQLite)
php artisan test --filter=PublicSeoTest   # a single test class
./vendor/bin/pint                         # format PHP code
```

## Production Deployment

1. Set `APP_ENV=production`, `APP_DEBUG=false` and `APP_URL` in `.env`
2. `composer install --no-dev --optimize-autoloader`
3. `npm ci && npm run build`: builds the public CSS and the admin SPA into `public/build`
4. `php artisan migrate --force`
5. `php artisan config:cache && php artisan route:cache && php artisan view:cache`
6. Point the web server's document root at `backend/public`. Laravel handles every route, including `/admin/*`, so no separate SPA hosting is needed.

## Security

- API authentication via Laravel Sanctum tokens
- Role-based access control with Spatie Permission
- CSRF protection on web forms
- Rate limiting on the public API and the contact form
- Input validation on endpoints
- SQL injection protection via Eloquent ORM
- The admin shell is marked `noindex, nofollow`

## Contributing

1. Fork the repository
2. Create your feature branch
3. Commit your changes
4. Push to the branch
5. Create a Pull Request

## License

This project is licensed under the MIT License.

## Support

For support, email support@sms.com or create an issue in the repository.

## Roadmap

- [ ] Student report cards
- [ ] SMS/Email notifications
- [ ] Parent portal
- [ ] Mobile app (React Native), which will use the `/api/public` endpoints
- [ ] Library management
- [ ] Transport management
- [ ] Hostel management
- [ ] HR & Payroll
- [ ] Timetable management
- [ ] Online examination system

## Credits

Developed with ❤️ using Laravel and Vue.js
