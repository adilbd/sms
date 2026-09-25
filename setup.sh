#!/bin/bash

echo "🏫 School Management System - Setup Script"
echo "=========================================="
echo ""

# Backend Setup
echo "📦 Setting up Backend..."
cd backend

# Check if .env exists
if [ ! -f .env ]; then
    echo "Creating .env file..."
    cp .env.example .env
fi

# Install dependencies
echo "Installing PHP dependencies..."
composer install --quiet

# Generate key if not set
if ! grep -q "APP_KEY=base64:" .env; then
    php artisan key:generate
fi

# Create database
echo "Setting up database..."
read -p "Enter MySQL root password: " -s DB_PASSWORD
echo ""

mysql -u root -p"$DB_PASSWORD" -e "CREATE DATABASE IF NOT EXISTS sms_db;" 2>/dev/null

if [ $? -eq 0 ]; then
    echo "✅ Database created successfully"
else
    echo "⚠️  Could not create database. Please create it manually: CREATE DATABASE sms_db;"
fi

# Run migrations
echo "Running migrations..."
php artisan migrate:fresh --seed --force

echo "✅ Backend setup complete!"
echo ""

# Frontend Setup
cd ../frontend

echo "📦 Setting up Frontend..."
echo "Installing npm dependencies..."
npm install

echo "✅ Frontend setup complete!"
echo ""

# Summary
echo "=========================================="
echo "✅ Setup Complete!"
echo ""
echo "To start the application:"
echo ""
echo "Terminal 1 (Backend):"
echo "  cd backend && php artisan serve"
echo ""
echo "Terminal 2 (Frontend):"
echo "  cd frontend && npm run dev"
echo ""
echo "Then visit: http://localhost:3000"
echo ""
echo "Default Login:"
echo "  Email: admin@sms.com"
echo "  Password: password"
echo ""
echo "=========================================="

