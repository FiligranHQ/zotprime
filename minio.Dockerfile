FROM minio/minio:RELEASE.2018-10-25T01-27-03Z

COPY docker/minio /usr/bin/
RUN chmod +x /usr/bin/minio
