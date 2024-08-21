#!/bin/bash

###############################
####### Запуск сервисов #######
###############################

# # Запуск Apache
# apache2ctl -D FOREGROUND &

# # Запуск Node.js
# cd /var/www/distros/mediawiki-services-chromium-render &&
# npm start &

# # Ожидание завершения всех процессов
# wait -n


cd /var/www/distros/mediawiki-services-chromium-render &&
npm start &
apache2ctl -D FOREGROUND