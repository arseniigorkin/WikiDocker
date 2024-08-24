##########################################################
##########################################################
##### Dockerfile для запуска сайтов с документацией ######
##########################################################
##########################################################

# 1. Используем образ Ubuntu 24.04 LTS
FROM ubuntu:24.04
LABEL maintainer="Arsenii Gorkin <gorkin@protonmail.com>"

# 2. Задаём аргументы
ARG NODE_VERSION='18.20.4'
ARG APACHE_WIKI_CONF='./apache2/wiki.conf'
ARG APACHE_DEV_WIKI_CONF='./apache2/dev.wiki.conf'
ARG STARTUP_SCRIPT='./scripts/startup.sh'
ARG DISTROS='./distros'
ARG WIKI_USER_NAME='wikiadmin'
ARG WIKI_USER_PWD='wikiadmin+vh93-h2=C_'

# 3. Устанавливаем необходимые пакеты
RUN apt update && apt install -y \
    apache2 \
    php \
    libapache2-mod-php \
    curl \
    git \
    sudo \
    nano \
    build-essential \
    software-properties-common \
    gnupg2 \
    htop \
    xz-utils \
    php-mbstring \
    php-xml \
    php-intl \
    locales \
    iputils-ping \
    mysql-client \
    imagemagick \
    php-mysql \
    python3-apt \
    php-curl

# 4. Настройка языка
RUN locale-gen ru_RU.UTF-8 && \
    update-locale LANG=ru_RU.UTF-8 \

# 5. Создаем пользователя без sudo
RUN useradd -m -s /bin/bash ${WIKI_USER_NAME} && \
    echo "${WIKI_USER_NAME}:${WIKI_USER_PWD}" | chpasswd && \
    usermod -aG www-data ${WIKI_USER_NAME}

# 6. Создание директорий для сайтов wiki
RUN mkdir -p /var/www/wiki  \
    /var/www/dev.wiki  \
    /var/www/private.wiki  \
    /var/www/private.dev.wiki  \
    /var/www/test.wiki  \
    /var/www/distros  \
    /var/www/wiki/uploads \
    /var/www/dev.wiki/uploads \
    /var/www/wiki/logs \
    /var/www/dev.wiki/logs && \
    chmod g+rxw -R /var/www && \
    chown -R www-data:www-data /var/www

# 7. Устанавливаем Node.js 18 напрямую
RUN curl -fsSL https://nodejs.org/dist/v${NODE_VERSION}/node-v${NODE_VERSION}-linux-x64.tar.xz | tar -xJf - -C /usr/local --strip-components=1 --no-same-owner && \
    ln -s /usr/local/bin/node /usr/bin/node && \
    ln -s /usr/local/bin/npm /usr/bin/npm

# 8. Устанавливаем Chromium
RUN add-apt-repository ppa:xtradeb/apps -y && \
    apt-get update && \
    apt-get install -y chromium

# 9. Настройка apache2
COPY ${APACHE_WIKI_CONF} /etc/apache2/sites-available/wiki.conf
COPY ${APACHE_DEV_WIKI_CONF} /etc/apache2/sites-available/dev.wiki.conf
RUN ln -s /etc/apache2/sites-available/wiki.conf /etc/apache2/sites-enabled/wiki.conf && \
    ln -s /etc/apache2/sites-available/dev.wiki.conf /etc/apache2/sites-enabled/dev.wiki.conf && \
    a2enmod proxy proxy_http rewrite && \
    a2ensite wiki.conf && \
    a2ensite dev.wiki.conf && \
    a2dissite 000-default.conf

# 10. Настройка порта apache2
#RUN echo 'Listen 8080' >> /etc/apache2/ports.conf

# 11. Устанавливаем зависимости и Python 3.10
RUN apt-get update && apt-get install -y software-properties-common && \
    add-apt-repository -y ppa:deadsnakes/ppa && \
    apt-get update && \
    apt-get install -y python3.10 python3.10-venv python3.10-dev python3-pip && \
    update-alternatives --install /usr/bin/python3 python3 /usr/bin/python3.10 1 && \
    python3.10 -m ensurepip --upgrade

# 12. Настройка модулей для Proton
COPY ${DISTROS} /var/www/distros
RUN chmod g+rxw -R /var/www/distros
RUN chown -R ${WIKI_USER_NAME} /var/www

# 13. Очистка и завершение
RUN apt clean && apt update && \
    rm -rf /var/lib/apt/lists/* && \
    rm -rf /var/www/html

# 14. Установка портов
EXPOSE 80 3030

# 15. Копируем скрипт запуска процессов в контейнер
COPY ${STARTUP_SCRIPT} /startup.sh
RUN chmod +x /startup.sh

# 16. Запуск процессов
ENTRYPOINT ["/startup.sh"]
CMD ["--dev"]
