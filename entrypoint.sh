#!/bin/bash
set -e

# Docker serves app at document root (/), not /ipmanage/ like XAMPP.
# Volume mount overwrites image files, so apply Docker .htaccess on every start.
if [ "${DOCKER_ENV:-}" = "1" ] && [ -f /var/www/html/.htaccess.docker ]; then
  cp /var/www/html/.htaccess.docker /var/www/html/.htaccess
  echo "[entrypoint] Applied Docker .htaccess (RewriteBase /)"
fi

# Ensure composer vendor packages exist when mounted from host volume
if [ ! -f /var/www/html/vendor/autoload.php ]; then
  if [ -d /opt/vendor-backup ] && [ "$(ls -A /opt/vendor-backup 2>/dev/null)" ]; then
    echo "[entrypoint] Restoring pre-built vendor packages into mounted volume..."
    mkdir -p /var/www/html/vendor
    cp -a /opt/vendor-backup/. /var/www/html/vendor/
  elif [ -f /var/www/html/composer.json ] && command -v composer >/dev/null 2>&1; then
    echo "[entrypoint] Installing composer dependencies..."
    composer install --no-dev --optimize-autoloader --no-interaction --working-dir=/var/www/html || true
  fi
fi

# Run database auto-upgrade/migration with auto-healing and error tolerance
php /var/www/html/includes/db_upgrade.php || echo "[entrypoint] Warning: Initial db_upgrade check encountered issues. App will continue auto-healing on request."

# Start a background loop for automated tasks with startup grace period
(
  # Wait 60s after container boot so Apache can serve requests smoothly without background contention
  sleep 60
  while true; do
    echo "[$(date)] Running Netwatch Monitor..."
    php /var/www/html/cron_netwatch.php || true
    sleep 10

    echo "[$(date)] Polling Manageable Switches..."
    php /var/www/html/cron_switch_poll.php || true
    sleep 10

    echo "[$(date)] Running Discovery Scanner..."
    php /var/www/html/cron_scanner.php || true
    
    sleep 300
  done
) &

# Start Apache in the foreground
apache2-foreground
