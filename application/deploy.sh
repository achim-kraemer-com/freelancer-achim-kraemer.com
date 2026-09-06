#!/usr/bin/env bash
set -euo pipefail

# Plesk's default CLI php is 8.3, das Projekt braucht >= 8.4 und der vHost
# laeuft auf 8.5 — beides (php und composer) ueber PATH auf 8.5 festnageln.
export PATH="/opt/plesk/php/8.5/bin:$PATH"

php -v | head -1

composer install --no-interaction
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console cache:clear
