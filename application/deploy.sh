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

# Die Web-Requests lesen ihre Umgebung aus .env.local, nicht aus dem Export
# oben — fehlt dort APP_ENV, laeuft die Live-App im dev-Modus.
if ! grep -qs '^APP_ENV=prod' .env.local; then
    echo "WARNUNG: .env.local setzt kein APP_ENV=prod — bitte auf dem Server pruefen." >&2
fi
