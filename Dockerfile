###############
## Сборка: docker build -t mybaseimage:latest .
## Запуск: docker run -d --name mywikibase --network wiki_net -p 8081:80 -p 8082:8080 -p 8083:8282 -v /Users/arseniigorkin/Desktop/RS\ CURRENT/Magistral/Wiki/pdf/Docker/Base/websites/test.wiki:/var/www/test.wiki  mybaseimage


# 1. Используем образ Ubuntu 24.04 LTS
FROM ubuntu:24.04
LABEL maintainer="Arsenii Gorkin <gorkin@protonmail.com>"

# 2. Устанавливаем необходимые пакеты
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
    iputils-ping \
    php-mysql \
    python3-apt


# 3. Создаем пользователя wikiadmin без sudo
RUN useradd -m -s /bin/bash wikiadmin && \
    echo 'wikiadmin:D3&oi88vh93-h2=C_' | chpasswd && \
    usermod -aG www-data wikiadmin

# 4. Создание директорий для сайтов wiki
RUN mkdir -p /var/www/wiki /var/www/dev.wiki /var/www/private.wiki /var/www/private.dev.wiki /var/www/test.wiki && \
    chmod g+rxw -R /var/www && \
    chown -R www-data:www-data /var/www

# 5. Устанавливаем Node.js 18 напрямую
RUN curl -fsSL https://nodejs.org/dist/v18.20.4/node-v18.20.4-linux-x64.tar.xz | tar -xJf - -C /usr/local --strip-components=1 --no-same-owner && \
    ln -s /usr/local/bin/node /usr/bin/node && \
    ln -s /usr/local/bin/npm /usr/bin/npm

# 6. Устанавливаем Chromium
RUN add-apt-repository ppa:xtradeb/apps -y && \
    apt-get update && \
    apt-get install -y chromium

# 7. Настройка Apache2
COPY Apache2/wiki.conf /etc/apache2/sites-available/wiki.conf
COPY Apache2/dev.wiki.conf /etc/apache2/sites-available/dev.wiki.conf
COPY Apache2/test.wiki.conf /etc/apache2/sites-available/test.wiki.conf
RUN ln -s /etc/apache2/sites-available/wiki.conf /etc/apache2/sites-enabled/wiki.conf && \
    ln -s /etc/apache2/sites-available/dev.wiki.conf /etc/apache2/sites-enabled/dev.wiki.conf && \
    ln -s /etc/apache2/sites-available/test.wiki.conf /etc/apache2/sites-enabled/test.wiki.conf && \
    a2enmod proxy && \
    a2enmod proxy_http

# 8. Активация конфигураций и деактивация сайта по умолчанию
RUN a2ensite wiki.conf && \
    a2ensite dev.wiki.conf && \
    a2ensite test.wiki.conf && \
    a2dissite 000-default.conf

# 9. Настройка порта Apache2
RUN echo 'Listen 8080' >> /etc/apache2/ports.conf && \
    echo 'Listen 8282' >> /etc/apache2/ports.conf

# 10. Активация необходимых модулей Apache (опционально)
RUN a2enmod rewrite

# 4. Устанавливаем зависимости и Python 3.10
RUN apt-get update && apt-get install -y software-properties-common && \
    add-apt-repository -y ppa:deadsnakes/ppa && \
    apt-get update && \
    apt-get install -y python3.10 python3.10-venv python3.10-dev python3-pip && \
    update-alternatives --install /usr/bin/python3 python3 /usr/bin/python3.10 1 && \
    python3.10 -m ensurepip --upgrade

# 11. Настройка модулей для Proton
COPY websites/distros /var/www/distros
RUN chmod g+rxw -R /var/www/distros
RUN chown -R wikiadmin /var/www
USER wikiadmin
RUN cd /var/www/distros/mediawiki-services-chromium-render && npm install
USER root

# 11. Очистка и завершение
RUN apt clean && apt update && \
    rm -rf /var/lib/apt/lists/* && \
    rm -rf /var/www/html

# 12. Установка портов
EXPOSE 80 8080 8282 3030

# 13. Копируем скрипт запуска процессов в контейнер
COPY scripts/startup.sh /startup.sh
RUN chmod +x /startup.sh

# 14. Запуск процессов
CMD ["/startup.sh"]
