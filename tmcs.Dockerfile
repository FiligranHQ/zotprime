FROM node:7-alpine

WORKDIR /usr/src/app
COPY ./tinymce-clean-server .
RUN npm install

EXPOSE 16342

CMD [ "npm", "start" ]
