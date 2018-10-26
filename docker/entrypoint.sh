#!/bin/sh

# Env vars
export APACHE_RUN_USER=${RUN_USER}
export APACHE_RUN_GROUP=${RUN_GROUP}
export APACHE_LOCK_DIR=/var/lock/apache2
export APACHE_PID_FILE=/var/run/apache2/apache2.pid
export APACHE_RUN_DIR=/var/run/apache2
export APACHE_LOG_DIR=/var/log/apache2

# Start log
/etc/init.d/rsyslog start

# Start rinetd
/etc/init.d/rinetd start

# NPM
cd /var/www/zotero/stream-server && npm install
cd /var/www/zotero/tinymce-clean-server && npm install

# Start Stream server
cd /var/www/zotero/stream-server && nodejs index.js &

# Start Clean server
cd /var/www/zotero/tinymce-clean-server && nodejs server.js &

# Chown
chown -R ${RUN_USER}:${RUN_GROUP} /var/log/apache2

# Chmod
chmod 777 /var/www/zotero/tmp

# Composer
cd /var/www/zotero && composer install

# Start Apache2
exec apache2 -DNO_DETACH -k start
