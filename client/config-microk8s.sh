#!/bin/sh

set -eux


#sed -i "s#'http://zotero.org/'#'http://api.zotprime:8080/'#g" zotero-client/resource/config.js 
#sed -i "s#'zotero.org'#'api.zotprime'#g" zotero-client/resource/config.js
sed -i "s#https://api.zotero.org/#http://api.zotprime:8080/#g" zotero-client/resource/config.js
sed -i "s#wss://stream.zotero.org/#ws://stream.zotprime:8081/#g" zotero-client/resource/config.js
sed -i "s#https://www.zotero.org/#http://api.zotprime:8080/#g" zotero-client/resource/config.js
sed -i "s#https://zoteroproxycheck.s3.amazonaws.com/test##g" zotero-client/resource/config.js



