# =====================================================================
#  Imagem unica usada por:
#   - Container App (web):   Apache + PHP servindo public/
#   - Container Apps Job:    override de comando -> php bin/extract.php cron
# =====================================================================
FROM php:8.2-apache

# Extensoes PHP: pdo_mysql (banco) e zip (geracao de .xlsx via ZipArchive).
# libzip-dev e necessario para compilar a extensao zip. curl ja vem embutido.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pdo_mysql zip \
    && a2enmod headers rewrite

# CA do Azure Database for MySQL Flexible Server (verificacao TLS estrita).
# Se o download falhar, a app ainda usa TLS sem verificacao estrita do CA.
RUN set -eux; \
    curl -fsSL https://cacerts.digicert.com/DigiCertGlobalRootG2.crt.pem \
        -o /etc/ssl/certs/azure-mysql-ca.pem || \
    echo "Aviso: nao foi possivel baixar o CA; TLS seguira sem verificacao estrita."

# Apache: DocumentRoot -> public/ (mantem src/, config/ fora da web).
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

WORKDIR /var/www/html

# Codigo da aplicacao.
COPY . /var/www/html

# Config do container (le tudo de env vars; sem segredos na imagem).
RUN cp config/config.docker.php config/config.php \
    && mkdir -p /tmp/azure-portal-runtime \
    && chown -R www-data:www-data /var/www/html /tmp/azure-portal-runtime

EXPOSE 80

# Comando padrao = servidor web (Apache). O Job sobrescreve o comando.
CMD ["apache2-foreground"]
