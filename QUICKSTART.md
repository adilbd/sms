# School Management System - Quick Start Guide

## 🎉 Your School Management System is Ready!

The application has been successfully generated with all the necessary files and database structure.

## 📦 What's Included

### Backend (Laravel 11)
✅ Complete database schema with 23 tables
✅ User authentication with Laravel Sanctum
✅ Role-based access control (Admin, Teacher, Student, Parent)
✅ RESTful API endpoints for all modules
✅ Models and relationships configured
✅ Database seeded with default admin user

### Frontend (Vue 3)
✅ Vue Router configured with routes
✅ Pinia store for state management
✅ Tailwind CSS for styling
✅ Login/Dashboard/Student management views
✅ API service with authentication interceptors
✅ Responsive layout with sidebar navigation

## 🚀 Starting the Application

### Option 1: Manual Start (Recommended)

#### Terminal 1 - Backend:
```bash
cd backend
php artisan serve
```
Backend will run on: **http://localhost:8000**

#### Terminal 2 - Frontend:
```bash
cd frontend
npm run dev
```
Frontend will run on: **http://localhost:3000**

### Option 2: Using the Setup Script
```bash
chmod +x setup.sh
./setup.sh
```

## 🔐 Default Login Credentials

Once both servers are running, visit **http://localhost:3000** and login with:

- **Email:** admin@sms.com
- **Password:** password

## 📚 Available Modules

### Core Features
- ✅ **Student Management** - Add, edit, view students with full profile
- ✅ **Teacher Management** - Manage teacher profiles and assignments
- ✅ **Class & Section Management** - Organize classes and sections
- ✅ **Subject Management** - Define subjects with marks configuration
- ✅ **Attendance Management** - Daily attendance tracking with bulk marking
- ✅ **Exam Management** - Schedule exams and manage results
- ✅ **Fee Management** - Fee structure, collection, and receipts
- ✅ **Academic Year Management** - Multiple academic year support
- ✅ **Parent Portal** - Parent-student relationships

### User Roles & Permissions
- **Admin** - Full system access
- **Teacher** - Attendance, exam results, student management
- **Student** - View own attendance, exams, results
- **Parent** - View children's data, attendance, fees

## 🗄️ Database Information

**Database Name:** scms
**Port:** 3307
**Tables Created:** 23 tables including:
- users, students, teachers, parents
- classes, sections, subjects
- attendances, exams, exam_results
- fee_types, fee_structures, fee_payments
- And more...

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

### Other Modules
- Classes: `/api/classes`
- Sections: `/api/sections`
- Subjects: `/api/subjects`
- Teachers: `/api/teachers`
- Exams: `/api/exams`
- Fees: `/api/fee-payments`

## 🎨 Frontend Routes

```
/login                  - Login page
/                       - Dashboard
/students               - Student list
/students/create        - Add new student
/students/:id           - Student details
/teachers               - Teacher list
/classes                - Class management
/subjects               - Subject management
/attendance             - Attendance list
/attendance/mark        - Mark attendance
/exams                  - Exam management
/fees                   - Fee management
/profile                - User profile
```

## 🛠️ Development Commands

### Backend
```bash
# Run migrations
php artisan migrate

# Fresh migration with seed
php artisan migrate:fresh --seed

# Create new controller
php artisan make:controller Api/YourController

# Create new model with migration
php artisan make:model YourModel -m

# Clear cache
php artisan cache:clear
php artisan config:clear
php artisan route:clear
```

### Frontend
```bash
# Install dependencies
npm install

# Run development server
npm run dev

# Build for production
npm run build

# Preview production build
npm run preview
```

## 📁 Project Structure

```
sms/
├── backend/
│   ├── app/
│   │   ├── Http/Controllers/Api/     # API Controllers
│   │   ├── Models/                   # Eloquent Models
│   │   └── ...
│   ├── database/
│   │   ├── migrations/               # Database migrations
│   │   └── seeders/                  # Database seeders
│   ├── routes/
│   │   └── api.php                   # API routes
│   └── .env                          # Environment config
│
├── frontend/
│   ├── src/
│   │   ├── views/                    # Page components
│   │   ├── components/               # Reusable components
│   │   ├── stores/                   # Pinia stores
│   │   ├── services/                 # API services
│   │   ├── router/                   # Vue Router config
│   │   └── assets/                   # CSS, images
│   ├── package.json
│   └── vite.config.js
│
├── README.md                         # Main documentation
└── setup.sh                          # Setup script
```

## 🐛 Troubleshooting

### Backend Issues

**Port 8000 already in use:**
```bash
lsof -ti:8000 | xargs kill -9
```

**Database connection error:**
- Check .env file database credentials
- Ensure MySQL is running on port 3307
- Verify database 'scms' exists

**Migration errors:**
```bash
php artisan migrate:fresh --seed
```

### Frontend Issues

**Node version warning:**
- The project uses Vite 4.x compatible with Node 16
- For best experience, upgrade to Node 18+

**npm install fails:**
```bash
rm -rf node_modules package-lock.json
npm install
```

**API connection fails:**
- Ensure backend is running on port 8000
- Check vite.config.js proxy settings
- Verify SANCTUM_STATEFUL_DOMAINS in backend .env

## 🔒 Security Notes

- Change default admin password immediately
- Update .env APP_KEY in production
- Never commit .env file to version control
- Enable HTTPS in production
- Configure proper CORS settings

## 📝 Next Steps

1. **Start Both Servers** (backend and frontend)
2. **Login** with admin credentials
3. **Explore** the dashboard and available modules
4. **Add Test Data**:
   - Create academic year
   - Add classes and sections
   - Add subjects
   - Register students
   - Mark attendance
5. **Customize** as per your requirements

## 🆘 Need Help?

- Check `README.md` for detailed documentation
- Review API responses in browser dev tools
- Check Laravel logs: `backend/storage/logs/laravel.log`
- Check Vite dev server console for frontend errors

## 🎯 Features Coming Soon

- Report cards generation
- Email/SMS notifications
- Timetable management
- Library management
- Transport management
- Mobile app

---

**Built with ❤️ using Laravel 11 & Vue 3**

Ready to manage your school efficiently! 🏫✨

