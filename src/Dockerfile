# Dockerfile
FROM php:8.3-apache

COPY ./ /var/www/html

# instalando os módulos necessários
RUN a2enmod rewrite
RUN a2enmod headers
RUN pecl install redis && docker-php-ext-enable redis

# Instala Supervisor para rodar múltiplos processos no container
RUN apt-get update && apt-get install -y supervisor
# Cria diretório para configuração do Supervisor
RUN mkdir -p /var/log/supervisor
# Copia o arquivo de configuração do Supervisor para o container
COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]

RUN service apache2 restart

EXPOSE 80
