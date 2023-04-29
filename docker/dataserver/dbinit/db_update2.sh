#!/usr/bin/env bash
set -xue

for i in db-updates/*/; do
    cd /var/www/zotero/misc/$i
    for j in *; do
        find . -type f \( ! -name *.sql \) -exec php {} \;
        find . -type f -name *.sql -exec bash -c 'mysql -h mysql -P 3306 -u root -pzotero zotero_master < {}' \;
    done    
done;
cd ../../