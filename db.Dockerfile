FROM mysql/mysql-server:5.6

COPY ./docker/db/low-memory-my.cnf /etc/mysql/my.cnf

