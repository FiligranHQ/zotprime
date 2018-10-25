# Zotero Prime - On-premise Zotero platform

Zotero Prime is a full packaged repository aimed to make on-premise [Zotero](https://www.zotero.org) deployment easier with the last versions of both Zotero client and server. This is the result of sleepness nights spent to deploy Zotero within my organization on a disconnected network. Feel free to open issues or pull requests if you did not managed to use it.

## Installation

*Install dependencies for client build*:
```bash
$ sudo apt install npm
```

*Clone the repository*:
```bash
$ mkdir /path/to/your/app && cd /path/to/your/app
$ git clone --recursive https://github.com/SamuelHassine/zotero-prime.git
$ cd zotero-prime
```

*Initialize all submodules*:
```bash
$ cd client/zotero 
$ git submodule init
$ git submodule update
$ cd ../zotero-standalone-build
$ git submodule init
$ git submodule update
$ cd ../../
```

*Run*:
```bash
$ docker-compose up -d
```

## Initialize installation

*Initialize databases*:
```bash
$ ./bin/init.sh
```

## Compile the client

For [m|l|w]: m=Mac, w=Windows, l=Linux

*Run*:
```bash
$ cd client
$ ./config.sh
$ cd zotero
$ npm install && npm run build
$ cd ../zotero-standalone-build
$ ./fetch_xulrunner.sh -p [m|l|w]
$ ./fetch_pdftools
$ ./scripts/dir_build -p [m|l|w]
```

## First usage

*Run*:
```bash
$ ./staging/zotero(.exe)
```

*Available endpoints*:
* API : http://localhost:8080
* Stream Server : ws://localhost:8081
* PHPMyAdmin : http://localhost:8082
* S3 Service : http://localhost:8083

*Default login/password*:
* Login : admin
* Password: admin

*Create a user*:
