#!/bin/bash
set -e

# Docker serves app at document root (/), not /ipmanage/ like XAMPP.
# Volume mount overwrites image files, so apply Docker .htaccess on every start.
if [ "${DOCKER_ENV:-}" = "1" ] && [ -f /var/www/html/.htaccess.docker ]; then
  cp /var/www/html/.htaccess.docker /var/www/html/.htaccess
  echo "[entrypoint] Applied Docker .htaccess (RewriteBase /)"
fi

# Run database auto-upgrade/migration
php /var/www/html/includes/db_upgrade.php

# Start a background loop for automated tasks
(
  while true; do
    echo "[$(date)] Running Parallel Discovery..."
    php /var/www/html/cron_scanner.php
    
    echo "[$(date)] Polling Manageable Switches..."
    php /var/www/html/cron_switch_poll.php

    echo "[$(date)] Running Netwatch Monitor..."
    php /var/www/html/cron_netwatch.php
    
    sleep 300
  done
) &

# Start Apache in the foreground
apache2-foreground
