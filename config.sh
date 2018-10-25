#!/bin/sh

sed -i 's#https://api.zotero.org/#http://localhost:8080#g' client/zotero-client/resource/config.js
sed -i 's#https://api.zotero.org/#http://localhost:8080#g' stream-server/config/default.js
sed -i "s#host: '',#host: 'redis',#g" stream-server/config/default.js
sed -i 's#wss://stream.zotero.org/#ws://localhost:8081#g' client/zotero-client/resource/config.js
sed -i 's#https://www.zotero.org/#http://localhost:8080#g' client/zotero-client/resource/config.js
sed -i 's#https://zoteroproxycheck.s3.amazonaws.com/test##g' client/zotero-client/resource/config.js
