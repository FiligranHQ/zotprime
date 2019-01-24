#!/bin/sh

sed -i'.bak' 's#https://api.zotero.org/#http://localhost:8080/#g' zotero-client/resource/config.js
sed -i'.bak' 's#wss://stream.zotero.org/#ws://localhost:8081/#g' zotero-client/resource/config.js
sed -i'.bak' 's#https://www.zotero.org/#http://localhost:8080/#g' zotero-client/resource/config.js
sed -i'.bak' 's#https://zoteroproxycheck.s3.amazonaws.com/test##g' zotero-client/resource/config.js
