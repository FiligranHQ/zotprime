#!/bin/sh
set -eux


#export APACHE_RUN_USER=${RUN_USER}

#(until rinetd -f -c /etc/rinetd.conf; do
#    echo "'rinetd' crashed with exit code $?. Restarting..." >&2
#    sleep 1
#done) & 

#exec httpd -e debug -DFOREGROUND -k start

if [ -e tmp/_key/secret-minio.txt ]
then
    source tmp/_key/secret-minio.txt
fi  

host=minio
port=9000
echo -n "waiting for TCP connection to $host:$port..."
#while ! nc -w 1 $host $port 2>/dev/null
while ! curl -s $host:$port 1>/dev/null
do
  echo -n .
  sleep 1
done
echo 'ok'

/usr/bin/mc config host add minio http://minio:9000 ${MINIO_ROOT_USER} ${MINIO_ROOT_PASSWORD};
exec mc admin trace -v -a minio;