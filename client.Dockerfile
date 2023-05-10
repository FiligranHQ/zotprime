
FROM node:16-alpine as intermediate
RUN set -eux; \ 
    apk update && apk upgrade && \
    apk add --update --no-cache git bash curl python3 zip perl rsync \
#    python2 \
#    util-linux \
    && rm -rf /var/cache/apk/*
WORKDIR /usr/src/app
COPY . .

RUN git submodule update --init client/zotero-build
RUN git submodule update --init client/zotero-standalone-build
RUN git submodule update --init client/zotero-client



WORKDIR /usr/src/app/client/zotero-client
RUN git checkout tags/6.0.23 -b v6.0.23
RUN rm -rf *
RUN git checkout -- .
RUN git submodule update --init --recursive

WORKDIR /usr/src/app/client/zotero-standalone-build 
RUN git checkout tags/6.0.23 -b v6.0.23
RUN rm -rf *
RUN git checkout -- .
RUN git submodule update --init --recursive

WORKDIR /usr/src/app/client/zotero-build 
RUN git checkout 00e854c6588f329b714250e450f4f7f663aa0222
RUN git status
RUN git submodule update --init --recursive

#WORKDIR /usr/src/app

#RUN git submodule update --init --recursive client/zotero-build
#RUN git submodule update --init --recursive client/zotero-standalone-build
#RUN git submodule update --init --recursive client/zotero-client

WORKDIR /usr/src/app/client/
RUN set -eux; \
    ./config.sh
WORKDIR /usr/src/app/client/zotero-client
#RUN set -eux; \
#     npx browserslist@latest --update-db --legacy-peer-deps
RUN set -eux; \
    npm install --legacy-peer-deps
RUN set -eux; \
    npm run build
WORKDIR /usr/src/app/client/zotero-standalone-build
RUN set -eux; \
    /bin/bash -c './fetch_xulrunner.sh -p l'
RUN set -eux; \
    ./fetch_pdftools
RUN set -eux; \
    ./scripts/dir_build -p l

FROM scratch AS export-stage
COPY --from=intermediate /usr/src/app/client/zotero-standalone-build/staging .