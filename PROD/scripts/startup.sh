#!/bin/bash

###############################
####### Запуск сервисов #######
###############################

if [ -z "$1" ]; then
  echo "Ошибка: не передан аргумент!"
  exit 1
fi

# Запуск Apache2 сервера
function apache_start {
  apache2ctl -D FOREGROUND
}

# Запуск Proton сервера
function proton_start {
  cd /var/www/distros/mediawiki-services-chromium-render &&
  npm start
}

# Преобразуем значение аргумента в нижний регистр
arg=$(echo "$1" | tr '[:upper:]' '[:lower:]')

if [ "$arg" == "--prod" ]; then
    echo "Запущен в режиме production"
    proton_start
    apache_start

elif [ "$arg" == "--dev" ]; then
    echo "Запущен в режиме development"
    proton_start
    apache_start

else
    echo "Неизвестное значение аргумента: $arg"
    exit 1
fi
