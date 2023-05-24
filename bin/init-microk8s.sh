#!/bin/sh

set -eux

microk8s kubectl -n zotprime exec -it $(microk8s kubectl -n zotprime get pods -l apps=zotprime-dataserver -o custom-columns=:metadata.name) -- sh -cux 'cd /var/www/zotero/misc && ./init-mysql.sh'
microk8s kubectl -n zotprime exec -it $(microk8s kubectl -n zotprime get pods -l apps=zotprime-dataserver -o custom-columns=:metadata.name) -- sh -cux 'cd /var/www/zotero/misc && ./db_update.sh'
microk8s kubectl -n zotprime exec -it $(microk8s kubectl -n zotprime get pods -l apps=zotprime-dataserver -o custom-columns=:metadata.name) -- sh -cux 'aws --endpoint-url "http://minio:9000" s3 mb s3://zotero'
microk8s kubectl -n zotprime exec -it $(microk8s kubectl -n zotprime get pods -l apps=zotprime-dataserver -o custom-columns=:metadata.name) -- sh -cux 'aws --endpoint-url "http://minio:9000" s3 mb s3://zotero-fulltext'
microk8s kubectl -n zotprime exec -it $(microk8s kubectl -n zotprime get pods -l apps=zotprime-dataserver -o custom-columns=:metadata.name) -- sh -cux 'aws --endpoint-url "http://localstack:4575" sns create-topic --name zotero'
