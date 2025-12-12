# Limit Order Exchange

This project is a Laravel 12 + Vue 3 (Inertia) application for managing limit orders and account balances.

## Requirements
- PHP 8.2+ with the `bcmath` extension enabled (required for all order math and balance calculations).
- Node.js (for Vite build/dev) and npm.
- Database (MySQL/PostgreSQL) with migrations applied (`php artisan migrate`).

## Setup
1. Install dependencies:
   ```bash
   composer install
   npm install
   ```
2. Copy env and set keys/DB:
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```
3. Run migrations:
   ```bash
   php artisan migrate
   ```
4. Serve the app (example):
   ```bash
   php artisan serve
   ```
5. Configure Pusher broadcasting in `.env`:
   ```bash
   BROADCAST_CONNECTION=pusher

   PUSHER_APP_ID=your-app-id-here
   PUSHER_APP_KEY=your-key-here
   PUSHER_APP_SECRET=your-secret-here
   PUSHER_APP_CLUSTER=eu

   VITE_PUSHER_APP_KEY="${PUSHER_APP_KEY}"
   VITE_PUSHER_APP_CLUSTER="${PUSHER_APP_CLUSTER}"
   ```
6. Refresh config cache after updating `.env`:
   ```bash
   php artisan config:clear
   ```

## API Access with Tokens
- API routes are protected by Sanctum. Generate personal access tokens from **Settings → API Keys** in the UI.
- Copy the token once and use it in requests:
  ```bash
  curl -H "Authorization: Bearer YOUR_TOKEN" -H "Accept: application/json" http://your-host/api/profile
  ```

## Build
- Production assets: `npm run build`
