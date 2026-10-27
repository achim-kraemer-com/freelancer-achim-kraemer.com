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

# Die Web-Requests lesen ihre Umgebung aus .env/.env.local oder aus der
# vHost-Konfiguration, nicht aus dem Export oben. Das muss stimmen, bevor
# irgendetwas laeuft: .env liegt im Repo und wird beim Checkout ueberschrieben,
# die Produktionswerte stehen in .env.local. Fehlt die Datei, liefe der Deploy
# mit den Repo-Vorgaben — und die Migrations gegen die falsche Datenbank.
if [ -f vendor/autoload.php ]; then
    WEB_ENV=$(unset APP_ENV APP_DEBUG; php -r \
        'require "vendor/autoload.php"; (new Symfony\Component\Dotenv\Dotenv())->bootEnv(".env"); echo $_SERVER["APP_ENV"];' \
        2>/dev/null || echo unbekannt)

    if [ "$WEB_ENV" != "prod" ]; then
        echo "ABBRUCH: Die Web-App wuerde als \"$WEB_ENV\" laufen, nicht als prod." >&2
        echo "Stehen die Produktionswerte in .env.local? Sonst dort hinterlegen." >&2
        exit 1
    fi
fi

# --no-dev: PHPUnit, MakerBundle und WebProfiler gehoeren nicht auf den Server.
composer install --no-dev --optimize-autoloader --no-interaction --no-progress
php bin/console doctrine:migrations:migrate --no-interaction
# cache:clear waermt den Cache gleich mit auf.
php bin/console cache:clear
