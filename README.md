# Limit Order Exchange

A small limit-order exchange built with **Laravel 12** and **Vue 3 (Inertia)**. Users place buy and sell limit
orders, orders are matched against the book, funds stay in escrow while an order is open, and both sides see
updates in real time.

> Built in December 2025 as a take-home technical assessment. I used Codex as a coding assistant. I reviewed
> every backend (PHP/Laravel) change line by line before committing, holding it to the standard of code I write
> myself. The assignment focused on the backend, so the Vue frontend got a lighter review.

## Features
- Limit orders with full-match execution at the maker's price and a 1.5% commission
- Escrow: buy orders reserve USD, sell orders lock the asset, and cancelling releases both
- Real-time order book, balance and trade updates via Pusher / Laravel Echo
- Trade history with pagination; Fortify auth including 2FA

## Design decisions
- **Money math with bcmath** on decimal strings for order values, balances and settlement.
- **Row locks inside DB transactions** (`lockForUpdate`) on users, assets and the matched maker order,
  so two concurrent orders can't spend the same balance or fill the same maker twice.
- **Actions + services:** `PlaceOrder` / `CancelOrder` call `OrderFundsService` (escrow) and
  `OrderMatchingService` (matching). Controllers stay thin.
- **Events are sent after commit:** `OrderMatched`, `OrderBookUpdated` and `UserBalancesUpdated` are queued
  inside the transaction and broadcast only after it commits, so clients never see a rolled-back trade.

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
