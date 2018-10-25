#!/bin/sh

docker-compose exec app-zotero /var/www/zotero/admin/create-user.sh ${1} ${2}
