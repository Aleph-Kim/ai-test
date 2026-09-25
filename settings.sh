cp .env.example .env
composer i
php artisan key:generate
php artisan migrate --force
