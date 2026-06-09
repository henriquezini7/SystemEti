#!/usr/bin/env bash
set -euo pipefail

APP_NAME="painel-etiquetas"
APP_DIR="/var/www/${APP_NAME}"
PORT="3037"
PHP_VERSION=""

if [[ $EUID -ne 0 ]]; then
  echo "Execute como root: sudo bash install.sh"
  exit 1
fi

cd "$(dirname "$0")"

if [[ ! -f public/index.php ]]; then
  echo "Arquivos do painel não encontrados. Rode o install.sh dentro da pasta extraída do painel."
  exit 1
fi

echo "==> Instalando dependências sem MySQL/MariaDB..."
apt-get update -y || true
DEBIAN_FRONTEND=noninteractive apt-get install -y nginx poppler-utils unzip curl php-cli php-fpm php-mbstring php-xml php-zip php-gd rsync

PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
FPM_SOCK="/run/php/php${PHP_VERSION}-fpm.sock"
if [[ ! -S "$FPM_SOCK" ]]; then
  FPM_SOCK=$(find /run/php -name 'php*-fpm.sock' | head -n 1 || true)
fi

echo "==> Copiando arquivos para ${APP_DIR}..."
mkdir -p "$APP_DIR"
rsync -a --delete \
  --exclude='app/config.local.php' \
  --exclude='storage/data/*' \
  --exclude='storage/uploads/*' \
  --exclude='storage/text/*' \
  --exclude='storage/exports/*' \
  ./ "$APP_DIR"/

mkdir -p "$APP_DIR/storage/uploads" "$APP_DIR/storage/text" "$APP_DIR/storage/exports" "$APP_DIR/storage/data"

if [[ ! -f "$APP_DIR/app/config.local.php" ]]; then
cat > "$APP_DIR/app/config.local.php" <<PHP
<?php
return [
    'app_name' => 'SystemETI',
    'app_url' => '',
    'upload_max_mb' => 500,
    'pdftotext_bin' => '/usr/bin/pdftotext',
    'pdfinfo_bin' => '/usr/bin/pdfinfo',
    'storage_driver' => 'json',
    'timezone' => 'America/Sao_Paulo',
];
PHP
else
  sed -i "s/'upload_max_mb'[[:space:]]*=>[[:space:]]*[0-9]\+/'upload_max_mb' => 500/" "$APP_DIR/app/config.local.php" || true
  if ! grep -q "timezone" "$APP_DIR/app/config.local.php"; then
    sed -i "/storage_driver/a\    'timezone' => 'America/Sao_Paulo'," "$APP_DIR/app/config.local.php" || true
  fi
fi

if [[ ! -f "$APP_DIR/storage/data/users.json" ]]; then
cat > "$APP_DIR/storage/data/users.json" <<'JSON'
[
    {
        "id": 1,
        "name": "Administrador",
        "email": "admin@local",
        "password_hash": "$2y$12$lQjUHjib0OF3weut9lcwyOrlrhVHiXUaom8vVTg.85PL8VrNKSYsK",
        "role": "admin",
        "created_at": "2026-01-01T00:00:00+00:00",
        "updated_at": "2026-01-01T00:00:00+00:00"
    }
]
JSON
fi

if [[ ! -f "$APP_DIR/storage/data/reports.json" ]]; then
  echo "[]" > "$APP_DIR/storage/data/reports.json"
fi
if [[ ! -f "$APP_DIR/storage/data/counter.json" ]]; then
  echo '{"report_id":0}' > "$APP_DIR/storage/data/counter.json"
fi

touch "$APP_DIR/storage/.installed"

chown -R www-data:www-data "$APP_DIR"
find "$APP_DIR/storage" -type d -exec chmod 775 {} \;
find "$APP_DIR/storage" -type f -exec chmod 664 {} \; || true

echo "==> Configurando PHP upload..."
for PHP_INI in "/etc/php/${PHP_VERSION}/fpm/php.ini" "/etc/php/${PHP_VERSION}/cli/php.ini"; do
  if [[ -f "$PHP_INI" ]]; then
    sed -i 's/^upload_max_filesize.*/upload_max_filesize = 500M/' "$PHP_INI" || true
    sed -i 's/^post_max_size.*/post_max_size = 540M/' "$PHP_INI" || true
    sed -i 's/^max_execution_time.*/max_execution_time = 1800/' "$PHP_INI" || true
    sed -i 's/^memory_limit.*/memory_limit = 2048M/' "$PHP_INI" || true
  fi
done

create_php_service() {
cat > "/etc/systemd/system/${APP_NAME}.service" <<EOF
[Unit]
Description=Painel Etiquetas PHP Server
After=network.target

[Service]
Type=simple
WorkingDirectory=${APP_DIR}/public
ExecStart=/usr/bin/php -d upload_max_filesize=500M -d post_max_size=540M -d max_execution_time=0 -d memory_limit=2048M -S 0.0.0.0:${PORT} -t ${APP_DIR}/public
Restart=always
RestartSec=3
User=www-data
Group=www-data

[Install]
WantedBy=multi-user.target
EOF
  systemctl daemon-reload
  systemctl enable "${APP_NAME}" >/dev/null 2>&1 || true
  systemctl restart "${APP_NAME}"
}

USE_NGINX=1
if systemctl is-enabled nginx 2>/dev/null | grep -q masked; then
  USE_NGINX=0
fi

if [[ "$USE_NGINX" == "1" && -n "${FPM_SOCK:-}" && -S "$FPM_SOCK" ]]; then
  echo "==> Configurando Nginx na porta ${PORT}..."
  cat > "/etc/nginx/sites-available/${APP_NAME}" <<NGINX
server {
    listen ${PORT};
    server_name _;
    root ${APP_DIR}/public;
    index index.php index.html;
    client_max_body_size 540M;

    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:${FPM_SOCK};
        fastcgi_read_timeout 1800;
    }

    location ~ /\. {
        deny all;
    }

    location ~* \.(json|txt|log)$ {
        deny all;
    }
}
NGINX
  ln -sf "/etc/nginx/sites-available/${APP_NAME}" "/etc/nginx/sites-enabled/${APP_NAME}"
  if nginx -t && systemctl restart "php${PHP_VERSION}-fpm" 2>/dev/null && systemctl restart nginx 2>/dev/null; then
    systemctl disable "${APP_NAME}" >/dev/null 2>&1 || true
    systemctl stop "${APP_NAME}" >/dev/null 2>&1 || true
    echo "==> Rodando via Nginx + PHP-FPM."
  else
    echo "⚠️  Nginx não reiniciou. Vou subir o painel por serviço PHP separado na porta ${PORT}."
    create_php_service
  fi
else
  echo "⚠️  Nginx está mascarado/bloqueado ou sem PHP-FPM. Vou subir por serviço PHP separado na porta ${PORT}."
  create_php_service
fi

if command -v ufw >/dev/null 2>&1; then
  ufw allow ${PORT}/tcp >/dev/null 2>&1 || true
fi

cat > "$APP_DIR/VERSAO.txt" <<TXT
Conferidor de Etiquetas v12 SEM MYSQL
Instalado em: $(date '+%Y-%m-%d %H:%M:%S')
Porta: ${PORT}
Armazenamento: JSON local em storage/data
Novidades: Registro obrigatório de etiquetas, bipagem de envio, bipagem de devolução, auditoria de bipes, etiquetas pendentes/enviadas/devolvidas, além do modo inteligente com Mercado Livre, Shopee, Jadlog/DANFE, DACE e separação por produto.
TXT

echo ""
echo "✅ Painel instalado/atualizado com sucesso, sem MySQL/MariaDB!"
echo "Acesse: http://IP-DA-VPS:${PORT}"
echo "Login: admin@local"
echo "Senha: admin123"
echo ""
echo "Dados salvos em: ${APP_DIR}/storage/data"
echo "Troque a senha do painel em Configurações."
