# SystemETI — imagem para Railway (PHP + servidor embutido + poppler-utils)
# Usa o servidor embutido do PHP (php -S), que é o mesmo modo fallback do install.sh.
# Evita a classe de problemas de MPM da imagem php:apache no Railway.
FROM php:8.2-cli

# Dependências do sistema:
# - poppler-utils  -> /usr/bin/pdftotext e /usr/bin/pdfinfo (leitura dos PDFs)
# - libs para extensões PHP usadas pelo painel (mbstring, gd, zip)
RUN apt-get update && apt-get install -y --no-install-recommends \
        poppler-utils \
        libzip-dev \
        libpng-dev \
        libonig-dev \
    && docker-php-ext-install mbstring gd zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Limites de upload/execução para PDFs grandes (igual ao install.sh da VPS)
RUN { \
        echo 'upload_max_filesize=500M'; \
        echo 'post_max_size=540M'; \
        echo 'memory_limit=2048M'; \
        echo 'max_execution_time=1800'; \
    } > /usr/local/etc/php/conf.d/systemeti-uploads.ini

# Código da aplicação
COPY . /var/www/html/
WORKDIR /var/www/html

# Entrypoint sobe o php -S na $PORT do Railway, com DocumentRoot em public/
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 8080
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
