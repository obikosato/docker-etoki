FROM php:8.4.1

RUN docker-php-ext-install pdo_mysql

RUN apt-get update
RUN apt-get install -y msmtp-mta

COPY ./msmtprc /etc/msmtprc