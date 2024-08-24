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
# Сервис запускается дважды, так как с первого раза некорректно работает. Это - тайна какая-то :)
function proton_start {
  echo 'Первый "холодный" запуск сервиса Proton'
  cd /var/www/distros/mediawiki-services-chromium-render &&
  
  # Запуск npm сервера в фоновом режиме
  npm start &
  
  # Сохранение PID запущенного процесса npm (он запустит Node.js)
  npm_pid=$!

  # Ожидание полного запуска
  echo 'Ожидаем полный старт 5 секунд...'
  sleep 5
  
#  # Получение реального PID процесса Node.js
#  node_pid=$(ps aux | grep 'node' | grep '/var/www/distros/mediawiki-services-chromium-render/server.js' | grep -v 'grep' | awk '{print $2}')
#  
#  # Проверка наличия PID
#  if [ -n "$node_pid" ]; then
#      echo "PID Proton сервиса: $node_pid"
#  else
#      echo "Не удалось получить PID процесса Node.js"
#      kill -SIGTERM "$npm_pid"  # Попытка завершить процесс npm, если не найден node_pid
#      exit 1  # Завершить скрипт с ошибкой, если PID не найден
#  fi
#  
#  # Ожидание сигнала для завершения
#  echo 'Остановка первого запуска сервиса Proton...'
#  kill -SIGTERM "$node_pid"  # Здесь нужно использовать $node_pid
#
#  # Проверка завершения процесса
#  sleep 2  # Дать время для завершения процесса
#  if kill -0 "$node_pid" 2>/dev/null; then  # Используйте $node_pid
#      echo "Процесс $node_pid не завершился корректно."
#      exit 1
#  else
#      echo "Процесс $node_pid успешно завершен."
#  fi
#  
#  # Повторный запуск сервиса, который будет работать постоянно
#  echo 'Повторный запуск Proton сервиса...'
#  npm start
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
