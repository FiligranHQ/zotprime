#!/bin/sh

set -eux

kubectl -n zotprime exec -it $(kubectl -n zotprime get pods -l apps=zotprime-dataserver -o custom-columns=:metadata.name) -- sh -cux 'cd /var/www/zotero/misc && ./init-mysql.sh'
kubectl -n zotprime exec -it $(kubectl -n zotprime get pods -l apps=zotprime-dataserver -o custom-columns=:metadata.name) -- sh -cux 'cd /var/www/zotero/misc && ./db_update.sh'
kubectl -n zotprime exec -it $(kubectl -n zotprime get pods -l apps=zotprime-dataserver -o custom-columns=:metadata.name) -- sh -cux 'aws --endpoint-url "http://minio:9000" s3 mb s3://zotero'
kubectl -n zotprime exec -it $(kubectl -n zotprime get pods -l apps=zotprime-dataserver -o custom-columns=:metadata.name) -- sh -cux 'aws --endpoint-url "http://minio:9000" s3 mb s3://zotero-fulltext'
kubectl -n zotprime exec -it $(kubectl -n zotprime get pods -l apps=zotprime-dataserver -o custom-columns=:metadata.name) -- sh -cux 'aws --endpoint-url "http://localstack:4575" sns create-topic --name zotero'
