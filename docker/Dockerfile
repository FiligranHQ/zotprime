# Use the official Docker Hub Ubuntu 18.04 base image
FROM ubuntu:18.04

# Update the base image
RUN DEBIAN_FRONTEND=noninteractive apt-get update && apt-get -y upgrade && apt-get -y dist-upgrade

# Setup PHP5
RUN DEBIAN_FRONTEND=noninteractive apt-get -y install software-properties-common
RUN DEBIAN_FRONTEND=noninteractive add-apt-repository ppa:ondrej/php
RUN DEBIAN_FRONTEND=noninteractive apt-get update
RUN DEBIAN_FRONTEND=noninteractive apt-get -y install apache2 libapache2-mod-php7.0 sudo rsyslog wget mysql-client curl nodejs
RUN DEBIAN_FRONTEND=noninteractive apt-get -y install php7.0-cli php7.0-xml php7.0-mysql php7.0-pgsql php7.0-json php7.0-curl php7.0-mbstring php7.0-intl php7.0-redis php7.0-dev php-pear composer

RUN sed -i 's/memory_limit = 128M/memory_limit = 1G/g' /etc/php/7.0/apache2/php.ini
RUN sed -i 's/max_execution_time = 30/max_execution_time = 300/g' /etc/php/7.0/apache2/php.ini
RUN sed -i 's/short_open_tag = Off/short_open_tag = On/g' /etc/php/7.0/apache2/php.ini
RUN sed -i 's/short_open_tag = Off/short_open_tag = On/g' /etc/php/7.0/cli/php.ini
RUN sed -i 's/display_errors = On/display_errors = Off/g' /etc/php/7.0/apache2/php.ini
RUN sed -i 's/error_reporting = E_ALL \& ~E_DEPRECATED \& ~E_STRICT/error_reporting = E_ALL \& ~E_NOTICE \& ~E_STRICT \& ~E_DEPRECATED/g' /etc/php/7.0/apache2/php.ini

# Setup igbinary
RUN DEBIAN_FRONTEND=noninteractive pecl install igbinary
RUN echo "extension=igbinary.so" > /etc/php/7.0/mods-available/igbinary.ini
#RUN ln -s /etc/php/7.0/mods-available/igbinary.ini /etc/php/7.0/cli/conf.d/20-igbinary.ini
#RUN ln -s /etc/php/7.0/mods-available/igbinary.ini /etc/php/7.0/apache2/conf.d/20-igbinary.ini

# Setup Memcached
RUN DEBIAN_FRONTEND=noninteractive apt-get -y install libmemcached11 libmemcachedutil2 build-essential libmemcached-dev libz-dev libxml2-dev zlib1g-dev libicu-dev g++
RUN DEBIAN_FRONTEND=noninteractive pecl download memcached-3.0.4 && tar xvzf memcached-3.0.4.tgz && cd memcached-3.0.4 && phpize && ./configure --enable-memcached-igbinary && make && make install
RUN echo "extension=memcached.so" > /etc/php/7.0/mods-available/memcached.ini
RUN ln -s /etc/php/7.0/mods-available/memcached.ini /etc/php/7.0/cli/conf.d/20-memcached.ini
RUN ln -s /etc/php/7.0/mods-available/memcached.ini /etc/php/7.0/apache2/conf.d/20-memcached.ini

# HTTP_Request2
RUN DEBIAN_FRONTEND=noninteractive composer require --no-plugins --no-scripts HTTP_Request2

# Setup awscli
RUN DEBIAN_FRONTEND=noninteractive apt-get -y install python python-pip git wget npm rinetd
RUN DEBIAN_FRONTEND=noninteractive pip install awscli

# Setup Apache2
RUN a2enmod rewrite

# Enable the new virtualhost
COPY zotero.conf /etc/apache2/sites-available/
RUN a2dissite 000-default
RUN a2ensite zotero

# Override gzip configuration
COPY gzip.conf /etc/apache2/conf-available/
RUN a2enconf gzip

# AWS local credentials
RUN mkdir ~/.aws  && bash -c 'echo -e "[default]\nregion = us-east-1" > ~/.aws/config' && bash -c 'echo -e "[default]\naws_access_key_id = zotero\naws_secret_access_key = zoterodocker" > ~/.aws/credentials'

# Chown log directory
RUN chown 33:33 /var/log/apache2

# Rinetd
RUN echo "0.0.0.0		8082		minio		9000" >> /etc/rinetd.conf

# Expose and entrypoint
COPY entrypoint.sh /
RUN chmod +x /entrypoint.sh
VOLUME /var/www/zotero
EXPOSE 80/tcp
EXPOSE 81/TCP
EXPOSE 82/TCP
ENTRYPOINT ["/entrypoint.sh"]

