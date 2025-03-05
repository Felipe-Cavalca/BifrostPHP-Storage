# Dockerfile.tests
FROM php:8.3-apache

COPY ./ /var/www/html

# instalando os módulos necessários
RUN a2enmod rewrite
RUN a2enmod headers

RUN service apache2 restart

EXPOSE 80
