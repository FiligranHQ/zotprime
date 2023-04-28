#!/bin/sh

set -eux

sed -i "s#'http://zotero.org/'#'http://localhost:8080/'#g" zotero-client/resource/config.js 
sed -i "s#'zotero.org'#'localhost'#g" zotero-client/resource/config.js
sed -i 's#https://api.zotero.org/#http://localhost:8080/#g' zotero-client/resource/config.js
sed -i 's#wss://stream.zotero.org/#ws://localhost:8081/#g' zotero-client/resource/config.js
sed -i 's#https://www.zotero.org/#http://localhost:8080/#g' zotero-client/resource/config.js
sed -i 's#https://zoteroproxycheck.s3.amazonaws.com/test##g' zotero-client/resource/config.js
