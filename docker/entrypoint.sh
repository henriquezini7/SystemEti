#!/usr/bin/env bash
set -e

# Railway (e a maioria das PaaS) injeta a porta em $PORT.
PORT="${PORT:-8080}"

# Faz o Apache escutar na porta certa.
sed -i "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:[0-9]*>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# Garante que o storage (montado como volume persistente) exista e seja gravável.
mkdir -p \
    /var/www/html/storage/data \
    /var/www/html/storage/uploads \
    /var/www/html/storage/text \
    /var/www/html/storage/exports
chown -R www-data:www-data /var/www/html/storage

exec apache2-foreground
