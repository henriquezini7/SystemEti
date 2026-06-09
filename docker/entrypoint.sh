#!/usr/bin/env bash
set -e

# Railway injeta a porta em $PORT.
PORT="${PORT:-8080}"

# Garante que o storage (volume persistente) exista e seja gravável.
mkdir -p \
    /var/www/html/storage/data \
    /var/www/html/storage/uploads \
    /var/www/html/storage/text \
    /var/www/html/storage/exports

# Servidor embutido do PHP servindo a pasta public/.
# Todas as rotas do painel são arquivos .php reais (login.php, upload.php, ...),
# então não há necessidade de rewrite de .htaccess. app/ e storage/ ficam fora do DocumentRoot.
exec php \
    -d upload_max_filesize=500M \
    -d post_max_size=540M \
    -d memory_limit=2048M \
    -d max_execution_time=1800 \
    -S 0.0.0.0:"$PORT" \
    -t /var/www/html/public
