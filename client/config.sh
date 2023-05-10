#!/bin/sh

set -e

HOST=localhost


echo -n "Enter the ip/hostaname of the server. Leave empty for default <localhost> : "
read HOST

echo -n "Note in case ip/hostname has a typo then to revert back changes run next commands <cd zotero-client; git restore resource/config.js; cd ../;>"
echo  
read -p "Are you shure you want to continue? " -n 1 -r
echo    # (optional) move to a new line
if [[ $REPLY =~ ^[Yy]$ ]]
then
  case $HOST in
   "")  SERVER=localhost ;;
    *)  SERVER=$HOST ;;
  esac
  echo "Host is set to $HOST"


  sed -i "s#'http://zotero.org/'#'http://$SERVER:8080/'#g" zotero-client/resource/config.js 
  sed -i "s#'zotero.org'#'$SERVER'#g" zotero-client/resource/config.js
  sed -i "s#https://api.zotero.org/#http://$SERVER:8080/#g" zotero-client/resource/config.js
  sed -i "s#wss://stream.zotero.org/#ws://$SERVER:8081/#g" zotero-client/resource/config.js
  sed -i "s#https://www.zotero.org/#http://$SERVER:8080/#g" zotero-client/resource/config.js
  sed -i "s#https://zoteroproxycheck.s3.amazonaws.com/test##g" zotero-client/resource/config.js


fi

