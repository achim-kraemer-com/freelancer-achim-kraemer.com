#!/usr/bin/env bash
composer self-update
composer install
php bin/console doctrine:migrations:migrate -n
php bin/console cache:clear
npm install
npm run build
