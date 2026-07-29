# Your Good Time API

Laravel 12 backend API for Your Good Time.

## Installation

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Update the MySQL credentials in `.env`:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=your_good_time
DB_USERNAME=root
DB_PASSWORD=
DB_CHARSET=utf8mb4
DB_COLLATION=utf8mb4_unicode_ci
```

## Serve

```bash
php artisan serve
```

## Migrate

```bash
php artisan migrate
```

## Storage Link

```bash
php artisan storage:link
```

## Health Check

```http
GET /api/health
```
