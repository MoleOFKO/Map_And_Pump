# Use the official PHP image with Apache
FROM php:8.2-apache

# Install PDO MySQL extension for database access
RUN docker-php-ext-install pdo pdo_mysql

# Enable Apache mod_rewrite for custom routing (e.g., frameworks or clean URLs)
RUN a2enmod rewrite

# Change Apache document root if your index.php is inside a public/ folder:
# ENV APACHE_DOCUMENT_ROOT /var/www/html/public
# RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
# RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/conf-available/*.conf

# Set working directory
WORKDIR /var/www/html

# Copy application files into the container
COPY . /var/www/html/

# Set proper permissions for the web server
RUN chown -R www-data:www-data /var/www/html

# Expose port 80 (Render automatically detects and routes traffic to this port)
EXPOSE 80