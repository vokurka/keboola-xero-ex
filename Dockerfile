FROM keboola/base-php56

MAINTAINER Vojtech Kurka <vokurka@keboola.com>

ENV APP_VERSION 0.0.1

WORKDIR /home

RUN git clone https://github.com/vokurka/keboola-xero-ex ./
RUN composer install --no-interaction
ENTRYPOINT php ./src/run.php --data=/data