#!/bin/sh

set -eux

sudo docker compose exec app-zotprime-dataserver bash -cux 'cd /var/www/zotero/misc && ./init-mysql.sh'
sudo docker compose exec app-zotprime-dataserver bash -cux 'aws --endpoint-url "http://minio:9000" s3 mb s3://zotero'
sudo docker compose exec app-zotprime-dataserver bash -cux 'aws --endpoint-url "http://minio:9000" s3 mb s3://zotero-fulltext'
sudo docker compose exec app-zotprime-dataserver bash -cux 'aws --endpoint-url "http://localstack:4575" sns create-topic --name zotero'
