FROM php:latest
RUN apt-get update && apt-get install cron -y
COPY autosave.php /srv/autosave.php
COPY autosave.cron /srv/autosave.cron
COPY entrypoint.sh /srv/entrypoint.sh
RUN chmod 0600 /srv/autosave.cron
RUN chmod 0700 /srv/entrypoint.sh
RUN crontab /srv/autosave.cron
WORKDIR /srv
RUN docker-php-ext-install bcmath
RUN printenv | grep -v "no_proxy" >> /etc/environment
ENTRYPOINT /srv/entrypoint.sh
