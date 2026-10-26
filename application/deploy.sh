#!/usr/bin/env bash
set -euo pipefail

# Plesk's default CLI php is 8.3, das Projekt braucht >= 8.4 und der vHost
# laeuft auf 8.5 — beides (php und composer) ueber PATH auf 8.5 festnageln.
export PATH="/opt/plesk/php/8.5/bin:$PATH"

# Die Deploy-Kommandos laufen immer gegen prod, unabhaengig davon, was in
# .env oder .env.local steht.
export APP_ENV=prod
export APP_DEBUG=0

php -v | head -1

# --no-dev: PHPUnit, MakerBundle und WebProfiler gehoeren nicht auf den Server.
composer install --no-dev --optimize-autoloader --no-interaction --no-progress
php bin/console doctrine:migrations:migrate --no-interaction
# cache:clear waermt den Cache gleich mit auf.
php bin/console cache:clear

# Die Web-Requests lesen ihre Umgebung aus .env/.env.local oder aus der
# vHost-Konfiguration, nicht aus dem Export oben. Ohne die exportierten
# Variablen genau so aufloesen lassen wie beim Request und nachsehen, was
# dabei herauskommt — ein grep auf .env.local allein gibt falschen Alarm.
WEB_ENV=$(unset APP_ENV APP_DEBUG; php -r \
    'require "vendor/autoload.php"; (new Symfony\Component\Dotenv\Dotenv())->bootEnv(".env"); echo $_SERVER["APP_ENV"];' \
    2>/dev/null || echo unbekannt)

if [ "$WEB_ENV" != "prod" ]; then
    echo "WARNUNG: Die Web-App laeuft als \"$WEB_ENV\", nicht als prod." >&2
fi
