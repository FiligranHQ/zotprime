
FROM node:16-alpine as intermediate

RUN set -eux; \ 
    apk update && apk upgrade && \
    apk add --update --no-cache git bash curl python3 zip perl rsync \
    && rm -rf /var/cache/apk/*
WORKDIR /usr/src/app
RUN mkdir client
RUN cd client
RUN git init
RUN git remote add -f origin https://github.com/uniuuu/zotprime
RUN echo "client/" >> .git/info/sparse-checkout
RUN git pull origin development
RUN git submodule update --init --recursive
WORKDIR /usr/src/app/client/
RUN set -eux; \
    ./config.sh
WORKDIR /usr/src/app/client/zotero-client
RUN set -eux; \
    npx browserslist@latest --update-db
RUN set -eux; \
    npm install
RUN set -eux; \
    npm run build
WORKDIR /usr/src/app/client/zotero-standalone-build
RUN set -eux; \
    ./fetch_xulrunner.sh -p l
RUN set -eux; \
    ./fetch_pdftools
RUN set -eux; \
    ./scripts/dir_build -p l


FROM scratch AS export-stage
COPY --from=intermediate /usr/src/app/client/zotero-standalone-build/staging/* .