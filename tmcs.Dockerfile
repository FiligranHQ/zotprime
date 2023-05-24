FROM node:7-alpine
ARG ZOTPRIME_VERSION=2

WORKDIR /usr/src/app
COPY ./tinymce-clean-server .
RUN npm install

EXPOSE 16342

CMD [ "npm", "start" ]
