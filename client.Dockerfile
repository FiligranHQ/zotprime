
FROM node:16-alpine as intermediate

RUN set -eux; \ 
    apk add --update --no-cache git \
    && rm -rf /var/cache/apk/*

WORKDIR /usr/src/app

COPY . .
RUN git submodule update --init --recursive
WORKDIR /usr/src/app/client/
RUN set -eux; \
    ./config.sh
WORKDIR /usr/src/app/client/zotero-client
RUN set -eux; \
     #cd /usr/src/app/zotero-client && 
     npx browserslist@latest --update-db
RUN set -eux; \
    #cd /usr/src/app/zotero-client && 
    npm install
RUN set -eux; \
    #cd /usr/src/app/zotero-client && 
    npm run build
WORKDIR /usr/src/app/client/zotero-standalone-build
RUN ls -lha
RUN set -eux; \
    #cd /usr/src/app/zotero-standalone-build && 
    ./fetch_xulrunner.sh -p l
RUN set -eux; \
    #cd /usr/src/app/zotero-standalone-build && 
    ./fetch_pdftools
RUN set -eux; \
    #cd /usr/src/app/zotero-standalone-build && 
    ./scripts/dir_build -p l


FROM scratch AS export-stage
COPY --from=intermediate /usr/src/app/zotero-standalone-build/staging/* .