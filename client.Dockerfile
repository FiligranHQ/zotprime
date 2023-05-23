
FROM node:16-alpine as intermediate
ARG ZOTPRIME_VERSION=2

RUN set -eux; \ 
    apk update && apk upgrade && \
    apk add --update --no-cache git bash curl python3 zip perl rsync 7zip \
#    python2 \
#    util-linux \
    && rm -rf /var/cache/apk/*
WORKDIR /usr/src/app
COPY . .

RUN git submodule update --init client/zotero-build
RUN git submodule update --init client/zotero-standalone-build
RUN git submodule update --init client/zotero-client



WORKDIR /usr/src/app/client/zotero-client
#RUN git checkout tags/6.0.23 -b v6.0.23
RUN git checkout tags/6.0.26 -b v6.0.26
RUN rm -rf *
RUN git checkout -- .
RUN git submodule update --init --recursive

WORKDIR /usr/src/app/client/zotero-standalone-build 
RUN git checkout tags/6.0.23 -b v6.0.23
RUN rm -rf *
RUN git checkout -- .
RUN git submodule update --init --recursive

WORKDIR /usr/src/app/client/zotero-build 
#RUN git checkout 00e854c6588f329b714250e450f4f7f663aa0222
RUN git checkout tags/apr20 -b v_apr20
RUN git status
RUN git submodule update --init --recursive

#WORKDIR /usr/src/app

#RUN git submodule update --init --recursive client/zotero-build
#RUN git submodule update --init --recursive client/zotero-standalone-build
#RUN git submodule update --init --recursive client/zotero-client

WORKDIR /usr/src/app/client/
#ARG CONFIG=config.sh
ARG HOST_DS=http://localhost:8080/
ARG HOST_ST=ws://localhost:9000/
RUN set -eux; \
        sed -i "s#https://api.zotero.org/#$HOST_DS#g" zotero-client/resource/config.js; \
        sed -i "s#wss://stream.zotero.org/#$HOST_ST#g" zotero-client/resource/config.js; \
        sed -i "s#https://www.zotero.org/#$HOST_DS#g" zotero-client/resource/config.js; \
        sed -i "s#https://zoteroproxycheck.s3.amazonaws.com/test##g" zotero-client/resource/config.js
#    ./$CONFIG
WORKDIR /usr/src/app/client/zotero-client
#RUN set -eux; \
#     npx browserslist@latest --update-db --legacy-peer-deps
ARG MLW=l
RUN set -eux; \
    npm install --legacy-peer-deps
RUN set -eux; \
    npm run build
WORKDIR /usr/src/app/client/zotero-standalone-build
RUN set -eux; \
    /bin/bash -c "./fetch_xulrunner.sh -p $MLW"
RUN set -eux; \
    ./fetch_pdftools
RUN set -eux; \
    ./scripts/dir_build -p $MLW

FROM scratch AS export-stage
COPY --from=intermediate /usr/src/app/client/zotero-standalone-build/staging .