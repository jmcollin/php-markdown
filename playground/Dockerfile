FROM php:8.3-apache

# Install OS-level dependencies required by PHP extensions
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libicu-dev \
        libonig-dev \
        curl \
    && rm -rf /var/lib/apt/lists/*

# Install required PHP extensions
RUN docker-php-ext-install \
    intl \
    mbstring

COPY php-security.ini /usr/local/etc/php/conf.d/security.ini

# Create a non-root user
RUN useradd -u 1000 -r -s /sbin/nologin www

# Configure Apache:
#  - Listen on port 8080 so no root privilege is needed for binding
#  - Set document root to public/ so src/ is never web-accessible
#  - Run worker processes as the www user
RUN sed -i 's/Listen 80/Listen 8080/' /etc/apache2/ports.conf \
    && sed -i 's/<VirtualHost \*:80>/<VirtualHost *:8080>/' \
        /etc/apache2/sites-available/000-default.conf \
    && sed -i 's|DocumentRoot /var/www/html|DocumentRoot /var/www/html/public|' \
        /etc/apache2/sites-available/000-default.conf \
    && printf '<Directory /var/www/html/public>\n    AllowOverride All\n    Require all granted\n</Directory>\n' \
        >> /etc/apache2/sites-available/000-default.conf \
    && sed -i 's/^User .*/User www/' /etc/apache2/envvars || true \
    && sed -i 's/^Group .*/Group www/' /etc/apache2/envvars || true

RUN a2enmod headers rewrite

# Grant ownership of the working directory to the non-root user
RUN chown -R www:www /var/www/html /var/log/apache2 /var/run/apache2

WORKDIR /var/www/html

USER www

EXPOSE 8080
