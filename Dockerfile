# Use the official PHP image with Apache
FROM php:8.4-apache

# Install required system packages and PostgreSQL dependencies
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libpng-dev \
    libzip-dev \
    zip \
    unzip \
    git \
    curl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install PHP extensions required by Laravel and PostgreSQL
RUN docker-php-ext-install pdo pdo_pgsql pgsql bcmath gd zip

# Enable Apache mod_rewrite for Laravel's URL routing
RUN a2enmod rewrite

# Install Composer securely
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set the working directory
WORKDIR /var/www/html

# Copy all application files to the container
COPY . .

# Install Laravel dependencies (excluding dev tools)
RUN composer install --no-dev --optimize-autoloader

# Point Apache to Laravel's public directory
RUN sed -ri -e 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!/var/www/html/public!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Set strict permissions for Laravel's storage and cache directories
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

COPY start.sh /usr/local/bin/start.sh
RUN chmod +x /usr/local/bin/start.sh

CMD ["/usr/local/bin/start.sh"]