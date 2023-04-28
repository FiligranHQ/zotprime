
FROM node:16-alpine as intermediate

WORKDIR /usr/src/app
#RUN mkdir -p /build
COPY ./client .
RUN set -eux; \
    ./config.sh
WORKDIR /usr/src/app/zotero-client
RUN set -eux; \
    npm install
RUN set -eux; \
    npm run build
WORKDIR /usr/src/app/zotero-standalone-build
RUN set -eux; \
    ./fetch_xulrunner.sh -p l
RUN set -eux; \
    ./fetch_pdftools
RUN set -eux; \
    ./scripts/dir_build -p l


FROM scratch AS export-stage
COPY --from=intermediate /usr/src/app/zotero-standalone-build/staging/* .