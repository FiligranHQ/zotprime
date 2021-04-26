#!/bin/sh

if [ -z "$1" -o -z "$2" -o -z "$3" ]; then
	echo "Usage: ./create-user.sh {UID} {username} {password} [email]"
	exit 1
fi

sudo docker-compose exec app-zotero /var/www/zotero/admin/create-user.sh ${1} ${2} ${3} ${4}
