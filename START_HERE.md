# 🏫 School Management System - Start Here

## What This Is

A single Laravel 11 app in `backend/` that serves:

- **Public website** at `/`: server-rendered Blade pages, SEO-ready, with news, events and a contact form
- **Admin panel** at `/admin`: a Vue 3 SPA (Pinia, Vue Router, Tailwind), bundled by Vite
- **JSON API** at `/api`: Sanctum tokens for the admin, plus unauthenticated `/api/public/*` endpoints for the mobile app

There is no separate frontend project to run.

---

## 🚀 Start the Application

```bash
cd backend
composer install && npm install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite          # or configure MySQL in .env
php artisan migrate:fresh --seed
composer dev
```

`composer dev` runs the PHP server, a queue worker, a log tail and the Vite dev server together.

Prefer Docker? See the Docker section in [README.md](README.md#docker).

---

## 🔐 Access the Application

- Public website: **http://localhost:8000**
- Admin panel: **http://localhost:8000/admin**
  - **Email:** admin@sms.com
  - **Password:** password

---

## 📱 What Works Today

### ✅ Working
1. **Public website**: home, about, admissions, contact, news, events, sitemap
2. **Admin login** and the **Dashboard** layout (the stats are placeholders)
3. **Students**: list with filters and pagination
4. **News & Events**: create, edit and publish posts
5. **APIs**: students, classes, sections, subjects, academic years, attendance, public content

### ⚠️ Placeholders
- Admin views: add/view student, teachers, classes, subjects, attendance, exams, fees, profile
- APIs: teachers, parents, exams and fees return empty data or 501. The dashboard endpoints aren't implemented yet.

---

## 📂 Project Files

```
sms/
├── backend/            ← The whole app (Laravel + Blade + Vue admin)
├── docker/             ← PHP image for docker compose
├── docker-compose.yml  ← MySQL + app + Vite
├── README.md           ← Full documentation
├── QUICKSTART.md       ← Quick reference
├── STATUS.md           ← What's done and what's pending
├── SUMMARY.md          ← Project overview
└── setup.sh            ← Interactive setup script (MySQL)
```

---

## 🛠️ Next Steps

1. Log in to `/admin` and explore
2. Publish a news post and view it on the public site
3. Read `STATUS.md` and pick a feature to build
4. Add admin views in `backend/resources/js/admin/views/` and register them in `backend/resources/js/admin/router/index.js`

---

## 🐛 Troubleshooting

### Page loads without styles or scripts?
Vite isn't running. Start it with `npm run dev` in `backend/`, or build the assets once with `npm run build`.

### Can't log in?
- Check that migrations and seeders have run: `php artisan migrate:fresh --seed`
- Check the database settings in `backend/.env`
- Verify the credentials: admin@sms.com / password

### Something else?
Check `backend/storage/logs/laravel.log`, or run `php artisan pail`.
