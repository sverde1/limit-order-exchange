# Limit Order Exchange

This project is a Laravel 12 + Vue 3 (Inertia) application for managing limit orders and account balances.

## Requirements
- PHP 8.2+ with the `bcmath` extension enabled (required for all order math and balance calculations).
- Node.js (for Vite build/dev) and npm.
- Database (MySQL/PostgreSQL) with migrations applied (`php artisan migrate`).

## Setup
1. Verify PHP has the `bcmath` extension; if missing, install it:
   ```bash
   php -m | grep bcmath
   ```
2. Install dependencies:
   ```bash
   composer install
   npm install
   ```
3. Copy env and set keys/DB:
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```
4. Run migrations:
   ```bash
   php artisan migrate
   ```
5. Serve the app (example):
   ```bash
   php artisan serve
   ```
6. Configure Pusher broadcasting in `.env`:
   ```bash
   BROADCAST_CONNECTION=pusher

   PUSHER_APP_ID=your-app-id-here
   PUSHER_APP_KEY=your-key-here
   PUSHER_APP_SECRET=your-secret-here
   PUSHER_APP_CLUSTER=eu

   VITE_PUSHER_APP_KEY="${PUSHER_APP_KEY}"
   VITE_PUSHER_APP_CLUSTER="${PUSHER_APP_CLUSTER}"
   ```
7. Refresh config cache after updating `.env`:
   ```bash
   php artisan config:clear
   ```

## Optional Nginx Snippet
Example server block (adjust host, paths, and PHP-FPM socket for your environment):
```
server {
    listen 80;
    server_name your-domain.test;

    root /path/to/limit-order-exchange/public;
    index index.php index.html index.htm;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }

    location ~* \.(?:css|js|jpg|jpeg|gif|png|svg|ico|woff|woff2|ttf|otf)$ {
        try_files $uri =404;
        expires max;
        log_not_found off;
    }

    location ~* /\. {
        deny all;
    }
}
```

## API Access with Tokens
- API routes are protected by Sanctum. Generate personal access tokens from **Settings → API Keys** in the UI.
- Copy the token once and use it in requests:
  ```bash
  curl -H "Authorization: Bearer YOUR_TOKEN" -H "Accept: application/json" http://your-host/api/profile
  ```

## Build
- Production assets: `npm run build`

## Testing
- Run the full test suite:
  - `php artisan test`
  - `composer test`
