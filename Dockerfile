FROM php:8.2-apache

# Install system dependencies and PHP extensions
RUN apt-get update && apt-get install -y \
    libmariadb-dev \
    libsnmp-dev \
    libssl-dev \
    libcurl4-openssl-dev \
    snmp \
    nmap \
    traceroute \
    iputils-ping \
    && docker-php-ext-install mysqli pdo pdo_mysql gettext snmp curl opcache \
    && pecl install redis && docker-php-ext-enable redis \
    && a2enmod rewrite

# Optimize Opcache configuration
RUN echo "opcache.memory_consumption=128" >> /usr/local/etc/php/conf.d/docker-php-ext-opcache.ini && \
    echo "opcache.interned_strings_buffer=8" >> /usr/local/etc/php/conf.d/docker-php-ext-opcache.ini && \
    echo "opcache.max_accelerated_files=4000" >> /usr/local/etc/php/conf.d/docker-php-ext-opcache.ini && \
    echo "opcache.revalidate_freq=2" >> /usr/local/etc/php/conf.d/docker-php-ext-opcache.ini && \
    echo "opcache.enable_cli=1" >> /usr/local/etc/php/conf.d/docker-php-ext-opcache.ini

# Set working directory
WORKDIR /var/www/html

# Copy project files
COPY . .

# Set permissions
RUN chown -R www-data:www-data /var/www/html && \
    chmod +x /var/www/html/entrypoint.sh

EXPOSE 80

# Use the entrypoint script to run both Apache and background cron
ENTRYPOINT ["bash", "/var/www/html/entrypoint.sh"]
