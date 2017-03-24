FROM php:7.1-cli

RUN groupadd --gid 1001 app && \
    useradd --uid 1001 --gid 1001 --shell /usr/sbin/nologin app

# install composer
WORKDIR /app

RUN chown -R app /app \
    && curl -s https://raw.githubusercontent.com/composer/getcomposer.org/a68fc08d2de42237ae80d77e8dd44488d268e13d/web/installer | php -- --quiet \
    && mv composer.phar /usr/bin/composer \
    && apt-get -q update \
    && apt-get install -y git

COPY . /app
RUN chmod +x /app/scraper \
    && mv config.local.php-docker config.local.php

USER app
RUN composer install
