# mbc-transactional-email

FROM dosomething/mb:latest
MAINTAINER Sergii Tkachenko <sergii@dosomething.org>

ARG COMPOSER_GITHUB_TOKEN
ENV RABBITMQ_HOST=192.168.99.10\
    RABBITMQ_PORT=5672\
    MB_RABBITMQ_MANAGEMENT_API_HOST=192.168.99.10\
    MB_RABBITMQ_MANAGEMENT_API_PORT=15672

COPY . /usr/src/mb/mbc-transactional-email
WORKDIR /usr/src/mb/mbc-transactional-email

RUN composer config --global github-oauth.github.com ${COMPOSER_GITHUB_TOKEN} \
    && composer install  --no-dev \
    && rm /root/.composer/auth.json

CMD [ "php", "mbc-transactional-email.php" ]
