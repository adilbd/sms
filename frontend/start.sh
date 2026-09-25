#!/bin/bash

echo "🚀 Starting School Management System Frontend..."
echo ""

# Load nvm
export NVM_DIR="$HOME/.nvm"
[ -s "$NVM_DIR/nvm.sh" ] && \. "$NVM_DIR/nvm.sh"

# Use Node 24
echo "📦 Switching to Node 24..."
nvm use 24

# Check versions
echo "✅ Node version: $(node --version)"
echo "✅ npm version: $(npm --version)"
echo ""

# Navigate to frontend directory
cd /Volumes/Document/Projects/Own/sms/frontend

# Start the dev server
echo "🎨 Starting Vite dev server..."
echo "Frontend will be available at: http://localhost:3000"
echo ""
npm run dev

