
############################
# mc image
############################

FROM minio/mc

COPY docker/miniomc/entrypoint.sh /
RUN chmod +x /entrypoint.sh
ENTRYPOINT ["/entrypoint.sh"]