# SystemETI — imagem para Railway (PHP + Apache + poppler-utils)
FROM php:8.2-apache

# Dependências do sistema:
# - poppler-utils  -> fornece /usr/bin/pdftotext e /usr/bin/pdfinfo (leitura dos PDFs)
# - libs para extensões PHP usadas pelo painel (mbstring, gd, zip)
RUN apt-get update && apt-get install -y --no-install-recommends \
        poppler-utils \
        libzip-dev \
        libpng-dev \
        libonig-dev \
    && docker-php-ext-install mbstring gd zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Habilita reescrita de URL e garante MPM único = prefork (exigido pelo mod_php).
# Sem isso o Apache aborta com "More than one MPM loaded" e o container fica em 502.
RUN a2dismod mpm_event mpm_worker 2>/dev/null || true \
    && a2enmod mpm_prefork rewrite

# Limites de upload/execução para PDFs grandes (igual ao install.sh da VPS)
RUN { \
        echo 'upload_max_filesize=500M'; \
        echo 'post_max_size=540M'; \
        echo 'memory_limit=2048M'; \
        echo 'max_execution_time=1800'; \
    } > /usr/local/etc/php/conf.d/systemeti-uploads.ini

# Código da aplicação
COPY . /var/www/html/

# Configuração do Apache (DocumentRoot -> public/) e entrypoint
COPY docker/000-default.conf /etc/apache2/sites-available/000-default.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh \
    && chown -R www-data:www-data /var/www/html

# Railway injeta a porta em $PORT; o entrypoint ajusta o Apache em runtime.
EXPOSE 8080
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
