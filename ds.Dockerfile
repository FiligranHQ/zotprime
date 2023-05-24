#FROM alpine:3 AS builder
#ARG ZOTPRIME_VERSION=2
##RUN apk add gnu-libiconv --update-cache --repository http://dl-cdn.alpinelinux.org/alpine/edge/community/ --allow-untrusted
##ENV LD_PRELOAD /usr/lib/preloadable_libiconv.so php
#RUN set -eux; \
#        apk update && apk upgrade --available
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
ARG ZOTPRIME_VERSION=2

#FROM php:8.1-alpine
#FROM php:alpine
#COPY --from=builder /tmp/rinetd/rinetd /usr/sbin/rinetd


#COPY --from=builder /usr/lib/preloadable_libiconv.so /usr/lib/preloadable_libiconv.so

#COPY --from=builder /usr/bin/aws /usr/bin/aws

#libapache2-mod-php7.0  
#php7.0-mysql php7.0-pgsql php7.0-redis php7.0-dev php-pear composer


#RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
#RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini" # DEV


#RUN set -eux; \
#        apk add gnu-libiconv --update-cache --repository http://dl-cdn.alpinelinux.org/alpine/edge/community/ --allow-untrusted

#ENV LD_PRELOAD /usr/lib/preloadable_libiconv.so php


#RUN set -eux; \
#        apk update && apk upgrade --available; \
#        apk add --update-cache --repository http://dl-cdn.alpinelinux.org/alpine/edge/community/ --allow-untrusted \
#        php82-apache2 \
#        php82-bcmath \
#        php82-common \
#        php82-cli \
#        php82-dev \
#        php82-pdo_pgsql \
#        php82-pear \
#        php82-pgsql \
#        php82-tidy \
#        php82-zip \
#        php82-pecl-msgpack \
#        php82-pecl-xdebug; \
RUN set -eux; \
        apk update && apk upgrade --available; \
        apk add --update --no-cache \
        apache2 \   
        apache2-utils \
        aws-cli \
        bash \
        curl \
        gettext-libs \
        git \
        gnutls-utils \
        icu-libs \
        libmemcached \
        libxslt \
        mariadb-client \
        memcached \
#        mysql-client \
        net-tools \
        php81 \
        php81-apache2 \
        php81-bcmath \
        php81-calendar \
        php81-cli \
        php81-common \
        php81-ctype \
        php81-curl \
        php81-dev \
        php81-dom \
        php81-exif \
        php81-ffi \
        php81-ftp \
        php81-gettext \
        php81-iconv \
        php81-intl \
        php81-json \
        php81-mbstring \
        php81-mysqli \
        php81-opcache \
        php81-pcntl \
        php81-pdo_mysql \
        php81-pdo_pgsql \
        php81-pear \
        php81-pecl-igbinary \
#        php81-pecl-mcrypt \
        php81-pecl-memcached \
        php81-pecl-msgpack \
        php81-pecl-redis \
        php81-pecl-xdebug \
        php81-pgsql \
        php81-phar \
        php81-posix \
        php81-session \
        php81-sodium \
        php81-shmop \
        php81-simplexml \
        php81-sockets \
        php81-sysvmsg \
        php81-sysvsem \
        php81-sysvshm \
        php81-tidy \
        php81-tokenizer \
        php81-xml \
        php81-xmlreader \
        php81-xmlwriter \
        php81-xsl \
        php81-zip \
        php81-zlib \
        runit \
        sudo \
        unzip \
        uwsgi \
        wget \
#        && rm -rf /var/cache/apk/*
#RUN set -eux; \
#        apk update && apk upgrade --available \
#        && apk add --update --no-cache \
#        autoconf \
#        dpkg-dev \
#        file \
#        g++ \
#        gcc \
#        libc-dev \
#        libmemcached-dev \
#        make \
#        pcre-dev icu-dev gettext-dev libxslt-dev libmemcached-dev libffi-dev ${PHPIZE_DEPS}; \
#        && pear channel-update pear.php.net; \
#        pecl channel-update pecl.php.net; \
#        pear config-set php_ini /etc/php81/php.ini; \
#        pear install http_request2 \
#        pecl install redis igbinary; \
#        pecl install --configureoptions 'with-libmemcached-dir="no" with-zlib-dir="no" with-system-fastlz="no" enable-memcached-igbinary="yes" enable-memcached-msgpack="no" enable-memcached-json="no" enable-memcached-protocol="no" enable-memcached-sasl="yes" enable-memcached-session="yes"' memcached \
        && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
        && composer require --no-plugins --no-scripts pear/http_request2 \
#        docker-php-ext-install -j$(nproc) mysqli intl pdo_mysql gettext sysvshm sysvsem sysvmsg xsl pcntl calendar exif ffi shmop sockets opcache; \
#        docker-php-ext-enable calendar memcached mysqli redis gettext intl pdo_mysql sysvshm sysvsem sysvmsg xsl pcntl igbinary exif ffi shmop sockets opcache; \
#        && apk del autoconf dpkg-dev file g++ gcc libc-dev make \
#        apk del pcre-dev icu-dev gettext-dev libxslt-dev libmemcached-dev libffi-dev ${PHPIZE_DEPS} \
#        && apk del ${BUILD_DEPENDS} \
#        && docker-php-source delete \
        && rm -rf /tmp/pear \
        && rm -rf /var/cache/apk/*


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
#build-essential
#        apache2-http2 \
#        ca-certificates \
#        libapache2-mod-php \
#        libapache2-mod-uwsgi \
#        rinetd \
#        rsyslog \
#        util-linux \
#        uwsgi-plugin-psgi \

#RUN set -eux; \
#        docker-php-ext-install iconv

#RUN set -eux; \
#        docker-php-ext-install redis 

#RUN set -eux; \
#        docker-php-source delete \
#        && apk del ${BUILD_DEPENDS}

#RUN pecl install ZendOpcache
#RUN docker-php-ext-install pgsql
#RUN docker-php-ext-install pdo_pgsql
#RUN docker-php-ext-install igbinary
#RUN docker-php-ext-install memcached
        

#RUN ls -lha /etc/
#RUN ls -lha /etc/php82/conf.d
#RUN ls -lha /etc/php82/php.ini
#RUN docker-php-ext-enable pdo_pgsql
#RUN docker-php-ext-enable pgsql
#RUN docker-php-ext-enable ZendOpcache

#RUN pecl install -o -f redis \
#&&  rm -rf /tmp/pear \
#&&  docker-php-ext-enable redis

#Debug Rsyslog
#RUN sed -i '/^DAEMON=\/usr\/sbin\/rsyslogd/a RSYSLOGD_OPTIONS="-dn"' /etc/init.d/rsyslog

#DEBUGGING
#RUN php -i
#RUN php -v
#RUN php --ini
#RUN php -m
#RUN composer show -p
#RUN aws --version 
#RUN set -eux; \ 
#    ls -lha /etc/php81


#RUN grep -R 'display_errors' /usr/local/etc/php
#RUN ls -lha /usr/local/etc/php
#RUN httpd -M
#RUN ls -lha /usr/lib/apache2/
#RUN find / -name mod_php81.so
#RUN ls -lha /etc/apache2/
#RUN grep -R 'LoadModule' /etc/
#RUN ls -lha /etc/apache2/conf.d/
#RUN ls -lha /usr/local/etc/php/conf.d/
#RUN whereis php

#RUN ls -lha /etc/php81/conf.d
#RUN ls -lha /usr/local/etc/php
#RUN ls -lha /etc/php81/php.ini
#RUN grep -R  'display_errors' /etc/
#RUN grep -R  'log_errors' /etc/
#RUN grep -R  'display_startup_errors' /etc/
#RUN grep -R -A 10 -B 10 'error_reporting' /etc/
#RUN php --ri iconv 
#RUN ls -lha /usr/bin/php 
#RUN php -i | grep iconv




RUN set -eux; \
        sed -i "s/#LoadModule\ rewrite_module/LoadModule\ rewrite_module/" /etc/apache2/httpd.conf; \
        sed -i "s/#LoadModule\ headers_module/LoadModule\ headers_module/" /etc/apache2/httpd.conf; \
#        sed -i "s/#LoadModule\ session_module/LoadModule\ session_module/" /etc/apache2/httpd.conf; \
#        sed -i "s/#LoadModule\ session_cookie_module/LoadModule\ session_cookie_module/" /etc/apache2/httpd.conf; \
#        sed -i "s/#LoadModule\ session_crypto_module/LoadModule\ session_crypto_module/" /etc/apache2/httpd.conf; \
        sed -i "s/#LoadModule\ deflate_module/LoadModule\ deflate_module/" /etc/apache2/httpd.conf; 
#        sed -i "s#^DocumentRoot \".*#DocumentRoot \"/var/www/zotero/htdocs\"#g" /etc/apache2/httpd.conf; \
#        sed -i "s#/var/www/localhost/htdocs#/var/www/zotero/htdocs#" /etc/apache2/httpd.conf; \
#        printf "\n<Directory \"/var/www/zotero/htdocs\">\n\tAllowOverride All\n</Directory>\n" >> /etc/apache2/httpd.conf

RUN set -eux; \
        sed -i 's/memory_limit = 128M/memory_limit = 1G/g' /etc/php81/php.ini; \
        sed -i 's/max_execution_time = 30/max_execution_time = 300/g' /etc/php81/php.ini; \
        sed -i 's/short_open_tag = Off/short_open_tag = On/g' /etc/php81/php.ini; \
        sed -i 's/display_errors = On/display_errors = Off/g' /etc/php81/php.ini; \
#        sed -i 's/display_errors = Off/display_errors = On/g' /etc/php81/php.ini; \    
        sed -i 's/error_reporting = E_ALL \& ~E_DEPRECATED \& ~E_STRICT/error_reporting = E_ALL \& ~E_NOTICE \& ~E_STRICT \& ~E_DEPRECATED/g' /etc/php81/php.ini
#        sed -i 's/error_reporting = E_ALL \& ~E_DEPRECATED \& ~E_STRICT/error_reporting = E_ALL \& ~E_NOTICE/g' /etc/php81/php.ini
#        sed -i 's/error_reporting = E_ALL \& ~E_DEPRECATED \& ~E_STRICT/error_reporting = E_ALL \| E_NOTICE \| E_WARNING/g' /etc/php81/php.ini

# Enable the new virtualhost
COPY docker/dataserver/zotero.conf /etc/apache2/conf.d/

# Override gzip configuration
COPY docker/dataserver/gzip.conf /etc/apache2/conf.d/

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

COPY dataserver/. /var/www/zotero/

RUN rm -rf /var/www/zotero/include/Zend
COPY Zend /var/www/zotero/include/Zend
COPY docker/dataserver/create-user.sh /var/www/zotero/admin/
COPY docker/dataserver/config.inc.php /var/www/zotero/include/config/
COPY docker/dataserver/dbconnect.inc.php /var/www/zotero/include/config/
COPY docker/dataserver/header.inc.php /var/www/zotero/include/
COPY docker/dataserver/Storage.inc.php /var/www/zotero/model/
COPY docker/dataserver/FullText.inc.php /var/www/zotero/model/
COPY docker/db/init-mysql.sh /var/www/zotero/misc/
COPY docker/db/db_update.sh /var/www/zotero/misc/
COPY docker/db/www.sql /var/www/zotero/misc/
COPY docker/db/shard.sql /var/www/zotero/misc/



ENV APACHE_RUN_USER=apache
ENV APACHE_RUN_GROUP=apache
ENV APACHE_LOCK_DIR=/var/lock/apache2
ENV APACHE_PID_FILE=/var/run/apache2/apache2.pid
ENV APACHE_RUN_DIR=/var/run/apache2
ENV APACHE_LOG_DIR=/var/log/apache2

COPY dataserver/. /var/www/zotero/

RUN rm -rf /var/www/zotero/include/Zend
COPY Zend /var/www/zotero/include/Zend
COPY docker/dataserver/create-user.sh /var/www/zotero/admin/
COPY docker/dataserver/config.inc.php /var/www/zotero/include/config/
COPY docker/dataserver/dbconnect.inc.php /var/www/zotero/include/config/
COPY docker/dataserver/header.inc.php /var/www/zotero/include/
COPY docker/dataserver/Storage.inc.php /var/www/zotero/model/
COPY docker/dataserver/FullText.inc.php /var/www/zotero/model/
COPY docker/db/init-mysql.sh /var/www/zotero/misc/
COPY docker/db/db_update.sh /var/www/zotero/misc/
COPY docker/db/www.sql /var/www/zotero/misc/
COPY docker/db/shard.sql /var/www/zotero/misc/


# Expose and entrypoint
COPY docker/dataserver/entrypoint.sh /
RUN chmod +x /entrypoint.sh
#VOLUME /var/www/zotero
EXPOSE 80/tcp
#EXPOSE 81/TCP
#EXPOSE 82/TCP
ENTRYPOINT ["/entrypoint.sh"]