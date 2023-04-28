
FROM node:16-alpine as intermediate

RUN set -eux; \ 
    apk add --update --no-cache git
WORKDIR /usr/src/app
#RUN mkdir -p /build
COPY ./client .
RUN set -eux; \
    ./config.sh
#WORKDIR /usr/src/app/zotero-client
RUN set -eux; \
    npx browserslist@latest --update-db
RUN set -eux; \
    cd /usr/src/app/zotero-client && npm install
RUN set -eux; \
    cd /usr/src/app/zotero-client && npm run build
#WORKDIR /usr/src/app/zotero-standalone-build
RUN set -eux; \
    cd /usr/src/app/zotero-standalone-build && ./fetch_xulrunner.sh -p l
RUN set -eux; \
    cd /usr/src/app/zotero-standalone-build && ./fetch_pdftools
RUN set -eux; \
    cd /usr/src/app/zotero-standalone-build && ./scripts/dir_build -p l


FROM scratch AS export-stage
COPY --from=intermediate /usr/src/app/zotero-standalone-build/staging/* .