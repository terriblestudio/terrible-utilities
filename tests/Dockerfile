FROM wordpress:php8.3-apache
RUN docker-php-ext-install ftp && mkdir -p /var/lib/terrible-backups && chown www-data:www-data /var/lib/terrible-backups
COPY tests/wp-cli.phar /usr/local/bin/wp
RUN chmod +x /usr/local/bin/wp && printf 'upload_max_filesize=64K\npost_max_size=64K\nmemory_limit=256M\n' > /usr/local/etc/php/conf.d/terrible-test.ini && printf '<Directory /var/www/html>\nLimitRequestBody 65536\n</Directory>\n' > /etc/apache2/conf-enabled/terrible-test.conf
