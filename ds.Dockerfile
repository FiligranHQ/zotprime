#FROM debian:bullseye-slim
#FROM alpine:3 AS builder
#RUN set -eux; \
#        apk update && apk upgrade
#RUN set -eux \
#    && apk add --no-cache \
#        python3 \
#        py3-pip \
#        build-base \
#        git \
#        autoconf \
#        automake \ 
##        util-linux \
#    && cd /tmp \
#    && git clone --depth=1 "https://github.com/samhocevar/rinetd" \
#    && cd rinetd \
#    && ./bootstrap \
#    && ./configure --prefix=/usr \
#    && make -j $(nproc) \
#    && strip rinetd \
##    && pip3 install --upgrade pip \
##    && pip3 install -v --no-cache-dir \
##    awscli \
#    && rm -rf /var/cache/apk/*

FROM alpine:3

#COPY --from=builder /tmp/rinetd/rinetd /usr/sbin/rinetd

#COPY --from=builder /usr/bin/aws /usr/bin/aws
#FROM php:8.1-alpine

# Update the base image
#RUN DEBIAN_FRONTEND=noninteractive apt-get update && apt-get -y upgrade && apt-get -y dist-upgrade

# Setup PHP5
#RUN DEBIAN_FRONTEND=noninteractive apt-get -y install software-properties-common
#RUN DEBIAN_FRONTEND=noninteractive add-apt-repository ppa:ondrej/php
#RUN DEBIAN_FRONTEND=noninteractive apt-get update
#RUN DEBIAN_FRONTEND=noninteractive apt-get -y install apache2 libapache2-mod-php7.0 sudo rsyslog wget mysql-client curl nodejs
#RUN DEBIAN_FRONTEND=noninteractive apt-get -y install php7.0-cli php7.0-xml php7.0-mysql php7.0-pgsql php7.0-json php7.0-curl php7.0-mbstring php7.0-intl php7.0-redis php7.0-dev php-pear composer


RUN set -eux; \
        apk update && apk upgrade && \
        apk add --update --no-cache \
            aws-cli \
            apache2 \
#            apache2-http2 \
            apache2-utils \
#            ca-certificates \
            bash \
            composer \
            curl \
            git \
            gnutls-utils \
#            libapache2-mod-php \
#            libapache2-mod-uwsgi \
            mariadb-client \
            memcached \
            net-tools \
            php \
            php-apache2 \
            php-common \
            php-cli \
            php-curl \
            php-dom \
            php81-dev \
            php-iconv \
            php-intl \
            php-mbstring \
            php-mysqli \
            php-pear \
            php-simplexml \
            php-tokenizer \
            php-xml \
            php-xmlwriter \
            php-zip \
            php81-pecl-memcached \
#            php81-pecl-mcrypt \
            php81-pecl-msgpack \
            php81-pecl-redis \
#            php81-pecl-http \
            php81-pecl-igbinary \
            php81-pecl-xdebug \
#            php81-pecl-zend-code \
#            rinetd \
            rsyslog \
            runit \
            unzip \
            uwsgi \
#            uwsgi-plugin-psgi \
            wget \
            && rm -rf /var/cache/apk/*
#RUN php -v
#RUN php --ini

#RUN php -m

#RUN composer show -p

#RUN aws --version 


#RUN set -eux; \ 
#    ls -lha /etc/php81


#RUN ls -lha /etc/php81/conf.d
#RUN ls -lha /usr/local/etc/php


RUN sed -i "s/#LoadModule\ rewrite_module/LoadModule\ rewrite_module/" /etc/apache2/httpd.conf \
        && sed -i "s/#LoadModule\ headers_module/LoadModule\ headers_module/" /etc/apache2/httpd.conf \
#    && sed -i "s/#LoadModule\ session_module/LoadModule\ session_module/" /etc/apache2/httpd.conf \
#    && sed -i "s/#LoadModule\ session_cookie_module/LoadModule\ session_cookie_module/" /etc/apache2/httpd.conf \
#    && sed -i "s/#LoadModule\ session_crypto_module/LoadModule\ session_crypto_module/" /etc/apache2/httpd.conf \
    && sed -i "s/#LoadModule\ deflate_module/LoadModule\ deflate_module/" /etc/apache2/httpd.conf \
    && sed -i "s#^DocumentRoot \".*#DocumentRoot \"/var/www/zotero/htdocs\"#g" /etc/apache2/httpd.conf \
    && sed -i "s#/var/www/localhost/htdocs#/var/www/zotero/htdocs#" /etc/apache2/httpd.conf \
    && printf "\n<Directory \"/var/www/zotero/htdocs\">\n\tAllowOverride All\n</Directory>\n" >> /etc/apache2/httpd.conf


#RUN grep -R 'LoadModule' /etc/

#RUN ls -lha /etc/apache2/

#RUN ls -lha /etc/apache2/conf.d/

#RUN ls -lha /usr/local/etc/php/conf.d/


#RUN whereis php


#    libc6 \
#            libdigest-hmac-perl \
#            libfile-util-perl \
#            libjson-xs-perl \
#            libplack-perl \
#            libswitch-perl 
#            mariadb-server \
#    nodejs \
#    npm \
#            vim \
#            awscli \

#RUN apt-get install vim -y
#build-essential

#RUN apt-get install -y php-pear composer nodejs

RUN set -eux; \
        sed -i 's/memory_limit = 128M/memory_limit = 1G/g' /etc/php81/php.ini; \
        sed -i 's/max_execution_time = 30/max_execution_time = 300/g' /etc/php81/php.ini; \
        sed -i 's/short_open_tag = Off/short_open_tag = On/g' /etc/php81/php.ini; \
#        sed -i 's/short_open_tag = Off/short_open_tag = On/g' /etc/php/7.4/cli/php.ini; \
        sed -i 's/display_errors = On/display_errors = Off/g' /etc/php81/php.ini; \
        sed -i 's/error_reporting = E_ALL \& ~E_DEPRECATED \& ~E_STRICT/error_reporting = E_ALL \& ~E_NOTICE \& ~E_STRICT \& ~E_DEPRECATED/g' /etc/php81/php.ini
#        sed -i 's/error_reporting = E_ALL \& ~E_DEPRECATED \& ~E_STRICT/error_reporting = E_ALL \& ~E_NOTICE/g' /etc/php81/php.ini

#RUN ls -lha /etc/php81/php.ini

#RUN grep -R  'display_errors' /etc/
#RUN grep -R  'log_errors' /etc/
#RUN grep -R  'display_startup_errors' /etc/
#RUN grep -R -A 10 -B 10 'error_reporting' /etc/

# Setup igbinary
#RUN apt-get install -y php-igbinary
# php-dev
#RUN pecl channel-update pecl.php.net
#RUN pecl install igbinary

#RUN echo "extension=igbinary.so" >> /etc/php/7.4/mods-available/igbinary.ini

#RUN ln -s /etc/php/7.0/mods-available/igbinary.ini /etc/php/7.0/cli/conf.d/20-igbinary.ini
#RUN ln -s /etc/php/7.0/mods-available/igbinary.ini /etc/php/7.0/apache2/conf.d/20-igbinary.ini


# Setup Memcached
#RUN apt-get -y install libmemcached11 libmemcachedutil2 build-essential libmemcached-dev libz-dev libxml2-dev zlib1g-dev libicu-dev g++
#RUN pecl download memcached-3.0.4 && tar xvzf memcached-3.0.4.tgz && cd memcached-3.0.4 && phpize && ./configure --enable-memcached-igbinary && make && make install


#RUN echo "extension=memcached.so" >> /etc/php/7.4/mods-available/memcached.ini
#RUN ln -s /etc/php/7.4/mods-available/memcached.ini /etc/php/7.4/cli/conf.d/20-memcached.ini
#RUN ln -s /etc/php/7.4/mods-available/memcached.ini /etc/php/7.4/apache2/conf.d/20-memcached.ini

# Setup awscli
#RUN apt-get -y install python3 python3-pip npm rinetd
#RUN pip install awscli
#RUN apt-get install -y awscli
#RUN apt-get install -y rinetd


# HTTP_Request2
#RUN pear install HTTP_Request2
#RUN apt-get install -y php-http-request2
#RUN apt-get install -y php-http

# Setup Apache2
#RUN a2enmod rewrite

# Enable the new virtualhost
#COPY docker/dataserver/zotero.conf /etc/apache2/sites-available/
#RUN a2dissite 000-default
#RUN a2ensite zotero

# Override gzip configuration
#COPY docker/dataserver/gzip.conf /etc/apache2/conf-available/
#


# Install npm
#RUN apt-get install -y npm

# Install rsyslog
#RUN apt-get install -y rsyslog

# Install rinetd
#RUN apt-get install -y rinetd

#RUN apt-get install -y sudo

#RUN apt-get update && apt-get install g++ 
#RUN set -eux; \
#            apt-get update && apt-get install ncdu -y
#RUN apt-get install libc6-dev unzip -y
#RUN set -eux; \
#        apt-get install php7.4-redis -y

#Install Nodejs
#RUN npm install -g n
#RUN n 16.20.0
#RUN n 6.17.1
#RUN npm install -g npm@9.6.5
#RUN apt-get purge --auto-remove -y nodejs
#RUN rm -rf /var/lib/apt/lists/*
#RUN rm -rf /etc/apt/sources.list.d/*
#RUN apt-get update
##RUN wget https://deb.nodesource.com/setup_19.x && bash -x setup_19.x
#RUN curl -fsSL https://deb.nodesource.com/setup_19.x | bash -
#RUN apt-get install -y nodejs

#RUN apt-get install -y libc6

#Install Yarn 
#RUN npm install --global yarn


#Debug Rsyslog
#RUN sed -i '/^DAEMON=\/usr\/sbin\/rsyslogd/a RSYSLOGD_OPTIONS="-dn"' /etc/init.d/rsyslog

#sed -i  "/'signature' => 'v4',/a 'endpoint' => 'http://' . Z_CONFIG::$S3_ENDPOINT," include/header.inc.php

# AWS local credentials
RUN set -eux; \
             mkdir ~/.aws  \
             && /bin/sh -c 'echo -e "[default]\nregion = us-east-1" > ~/.aws/config' \
             && /bin/sh -c 'echo -e "[default]\naws_access_key_id = zotero\naws_secret_access_key = zoterodocker" > ~/.aws/credentials'



RUN set -eux; \
        rm -rvf /var/log/apache2; \
        mkdir -p /var/log/apache2; \
# Chown log directory
        chown 100:101 /var/log/apache2; \
# Apache logs print docker logs
        ln -sfT /dev/stdout /var/log/apache2/access.log; \
        ln -sfT /dev/stderr /var/log/apache2/error.log; \
        ln -sfT /dev/stdout /var/log/apache2/other_vhosts_access.log; \
# Chown log directory
        chown -R --no-dereference 100:101 /var/log/apache2 

# Rinetd
#RUN set -eux; \
#        echo "0.0.0.0		8082		minio		9000" >> /etc/rinetd.conf

#Install uws
#WORKDIR /var/

ENV APACHE_RUN_USER=apache
ENV APACHE_RUN_GROUP=apache
ENV APACHE_LOCK_DIR=/var/lock/apache2
ENV APACHE_PID_FILE=/var/run/apache2/apache2.pid
ENV APACHE_RUN_DIR=/var/run/apache2
ENV APACHE_LOG_DIR=/var/log/apache2

# Expose and entrypoint
COPY docker/dataserver/entrypoint.sh /
RUN chmod +x /entrypoint.sh
VOLUME /var/www/zotero
EXPOSE 80/tcp
#EXPOSE 81/TCP
#EXPOSE 82/TCP
ENTRYPOINT ["/entrypoint.sh"]

