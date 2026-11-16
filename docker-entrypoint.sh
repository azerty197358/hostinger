#!/bin/sh
set -e

DATA_DIR=/var/www/html/data
PORT=${PORT:-80}

mkdir -p "$DATA_DIR" "$DATA_DIR/cache" "$DATA_DIR/log" "$DATA_DIR/uploads"
chown -R www-data:www-data "$DATA_DIR" || true

# Railway (and some other PaaS) inject $PORT; Apache must listen on it.
sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/\\*:80/\\*:${PORT}/g" /etc/apache2/sites-available/000-default.conf

cron
exec apache2-foreground
