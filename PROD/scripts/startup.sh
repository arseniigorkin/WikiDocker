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

  # Сохранение PID запущенного процесса
  npm_pid=$!
  echo "PID Proton сервиса: $npm_pid"
  
  # Ожидание завершения первого запуска
  echo 'Ожидаем полный старт 5 секунд...'
  sleep 5
    
  # Ожидание сигнала для завершения
  echo 'Остановка первого запуска сервиса Proton...'
  kill -SIGTERM $npm_pid
  
  # Убедимся, что процесс завершился до повторного запуска
  echo 'Ожидаем завершения процесса...'
  wait $npm_pid
  
  # Повторный запуск сервиса, который будет работать постоянно
  echo 'Повторный запуск Proton сервиса...'
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
