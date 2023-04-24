#!/bin/sh
set -x
# Env vars
export APACHE_RUN_USER=${RUN_USER}
export APACHE_RUN_GROUP=${RUN_GROUP}
export APACHE_LOCK_DIR=/var/lock/apache2
export APACHE_PID_FILE=/var/run/apache2/apache2.pid
export APACHE_RUN_DIR=/var/run/apache2
export APACHE_LOG_DIR=/var/log/apache2

# Start log
#/etc/init.d/rsyslog start

# Start rinetd
/etc/init.d/rinetd start

a2enmod rewrite
a2dissite 000-default
a2ensite zotero
a2enconf gzip

# NPM
#cd /var/www/zotero/stream-server 
#npm cache clean --force 
#npx npm-check-updates -u
#npm update
#npm install
#npm update
#yarn add uWebSockets.js@uNetworking/uWebSockets.js#v20.23.0

#cd /var/www/zotero/tinymce-clean-server && npm install

# Start Stream server
#cd /var/www/zotero/stream-server && node index.js &

# Start Clean server
#cd /var/www/zotero/tinymce-clean-server && node server.js &

# Chown
chown -R ${RUN_USER}:${RUN_GROUP} /var/log/apache2

# Chmod
chmod 777 /var/www/zotero/tmp

# Elastica Composer
#cd /var/www/zotero/include/Elastica && composer -v install

# Composer
#cd /var/www/zotero && composer update && composer -vv install
cd /var/www/zotero && composer -vv install


# Start Apache2
exec apache2 -e debug -DNO_DETACH -k start
