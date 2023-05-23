#!/bin/sh

set -eux


#sed -i "s#'http://zotero.org/'#'http://api.projectdev.net/'#g" zotero-client/resource/config.js 
#sed -i "s#'zotero.org'#'api.projectdev.net'#g" zotero-client/resource/config.js
sed -i "s#https://api.zotero.org/#http://api.projectdev.net/#g" zotero-client/resource/config.js
sed -i "s#wss://stream.zotero.org/#ws://stream.projectdev.net/#g" zotero-client/resource/config.js
sed -i "s#https://www.zotero.org/#http://api.projectdev.net/#g" zotero-client/resource/config.js
sed -i "s#https://zoteroproxycheck.s3.amazonaws.com/test##g" zotero-client/resource/config.js



