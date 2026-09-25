# School Management System (SMS)

A comprehensive School Management System built with Laravel 11 (Backend API) and Vue 3 (Frontend).

## Features

### Core Modules
- **Student Management**: Complete student lifecycle management with admission, enrollment, and tracking
- **Teacher Management**: Teacher profiles, qualifications, and subject assignments
- **Parent Management**: Parent information and student relationships
- **Class & Section Management**: Hierarchical class structure with sections
- **Subject Management**: Subject definitions and assignments
- **Attendance Management**: Daily attendance tracking with bulk marking
- **Exam Management**: Exam scheduling, result entry, and report generation
- **Fee Management**: Fee structure, collection, and receipt generation
- **Academic Year Management**: Multiple academic year support

### User Roles & Permissions
- **Admin**: Full system access
- **Teacher**: Student management, attendance, exam results
- **Student**: View attendance, exams, results
- **Parent**: View children's attendance, exams, fees

## Tech Stack

### Backend
- Laravel 11
- MySQL Database
- Laravel Sanctum (API Authentication)
- Spatie Laravel Permission (Role & Permission Management)

### Frontend
- Vue 3 (Composition API)
- Vue Router 4
- Pinia (State Management)
- Tailwind CSS
- Axios (HTTP Client)
- Vite (Build Tool)

## Project Structure

```
sms/
├── backend/              # Laravel API Backend
│   ├── app/
│   │   ├── Http/Controllers/Api/
│   │   └── Models/
│   ├── database/
│   │   ├── migrations/
│   │   └── seeders/
│   └── routes/
│       └── api.php
└── frontend/            # Vue 3 Frontend
    ├── src/
    │   ├── assets/
    │   ├── components/
    │   ├── router/
    │   ├── services/
    │   ├── stores/
    │   ├── views/
    │   ├── App.vue
    │   └── main.js
    └── package.json
```

## Installation

### Prerequisites
- PHP 8.2+
- Composer
- Node.js 18+ & npm
- MySQL 8.0+

### Backend Setup

1. Navigate to backend directory:
```bash
cd backend
```

2. Install PHP dependencies:
```bash
composer install
```

3. Configure environment:
```bash
cp .env.example .env
```

4. Update `.env` file with your database credentials:
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=sms_db
DB_USERNAME=root
DB_PASSWORD=your_password
```

5. Create database:
```bash
mysql -u root -p
CREATE DATABASE sms_db;
exit;
```

6. Run migrations and seeders:
```bash
php artisan migrate:fresh --seed
```

7. Start the development server:
```bash
php artisan serve
```

Backend API will be available at `http://localhost:8000`

### Frontend Setup

1. Navigate to frontend directory:
```bash
cd frontend
```

2. Install npm dependencies:
```bash
npm install
```

3. Start the development server:
```bash
npm run dev
```

Frontend will be available at `http://localhost:3000`

## Default Credentials

After seeding the database, use these credentials to login:

- **Email**: admin@sms.com
- **Password**: password

## API Endpoints

### Authentication
- `POST /api/login` - Login
- `POST /api/logout` - Logout
- `GET /api/me` - Get authenticated user
- `POST /api/change-password` - Change password

### Students
- `GET /api/students` - List students
- `POST /api/students` - Create student
- `GET /api/students/{id}` - Get student
- `PUT /api/students/{id}` - Update student
- `DELETE /api/students/{id}` - Delete student

### Attendance
- `GET /api/attendances` - List attendance
- `POST /api/attendances` - Mark attendance
- `POST /api/attendances/bulk` - Bulk mark attendance
- `GET /api/attendances/report/{student}` - Student attendance report

### Exams
- `GET /api/exams` - List exams
- `POST /api/exams` - Create exam
- `POST /api/exams/{id}/publish` - Publish exam results

### Fees
- `GET /api/fee-payments` - List fee payments
- `POST /api/fee-payments` - Record payment
- `GET /api/fee-payments/student/{student}` - Student payment history

## Database Schema

### Main Tables
- `users` - User accounts
- `students` - Student profiles
- `teachers` - Teacher profiles
- `parents` - Parent information
- `classes` - Class definitions
- `sections` - Section definitions
- `subjects` - Subject definitions
- `academic_years` - Academic year management
- `attendances` - Daily attendance records
- `exams` - Exam definitions
- `exam_schedules` - Exam timetable
- `exam_results` - Student exam results
- `fee_types` - Fee categories
- `fee_structures` - Class-wise fee structure
- `fee_payments` - Fee payment records

## Development

### Adding New Features

1. **Backend**: Create controller, model, and migration
```bash
php artisan make:controller Api/YourController
php artisan make:model YourModel -m
```

2. **Frontend**: Create view component and add route
```bash
# Create component in src/views/
# Add route in src/router/index.js
```

### Running Tests
```bash
# Backend
cd backend
php artisan test

# Frontend
cd frontend
npm run test
```

## Production Deployment

### Backend
1. Set `APP_ENV=production` in `.env`
2. Run `php artisan config:cache`
3. Run `php artisan route:cache`
4. Run `php artisan view:cache`
5. Configure web server (Apache/Nginx)

### Frontend
1. Build for production: `npm run build`
2. Deploy `dist/` folder to web server
3. Configure web server for SPA routing

## Security

- API authentication via Laravel Sanctum tokens
- Role-based access control with Spatie Permission
- CSRF protection enabled
- Input validation on all endpoints
- SQL injection protection via Eloquent ORM

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
- [ ] Mobile app (React Native)
- [ ] Library management
- [ ] Transport management
- [ ] Hostel management
- [ ] HR & Payroll
- [ ] Timetable management
- [ ] Online examination system

## Credits

Developed with ❤️ using Laravel and Vue.js

