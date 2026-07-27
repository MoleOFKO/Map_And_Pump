# Use the official PHP image with Apache
FROM php:8.2-apache

# Install required extensions
RUN docker-php-ext-install pdo pdo_mysql mysqli

# Enable Apache modules
RUN a2enmod rewrite
RUN a2enmod headers

# Set HomePage.php as the default landing page
RUN sed -i 's/DirectoryIndex index.php index.html/DirectoryIndex HomePage.php index.php index.html/' /etc/apache2/mods-enabled/dir.conf

# Copy PHP production configuration
COPY --from=php:8.2-apache /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini

# Configure PHP for production
RUN echo "display_errors = Off" >> /usr/local/etc/php/php.ini && \
    echo "log_errors = On" >> /usr/local/etc/php/php.ini && \
    echo "error_log = /var/log/php_errors.log" >> /usr/local/etc/php/php.ini

# Set working directory
WORKDIR /var/www/html

# Copy application files into the container
COPY . /var/www/html/

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html && \
    chmod -R 755 /var/www/html

# Create log file
RUN touch /var/log/php_errors.log && \
    chown www-data:www-data /var/log/php_errors.log

# Expose port 80
EXPOSE 80

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=5s --retries=3 \
    CMD curl -f http://localhost/ || exit 1