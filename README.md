# ZotPrime V2 - On-premise Zotero platform

ZotPrime is a full packaged repository aimed to make on-premise [Zotero](https://www.zotero.org) deployment easier with the last versions of both Zotero client and server. This is the result of sleepness nights spent to deploy Zotero within my organization on a disconnected network. Feel free to open issues or pull requests if you did not manage to use it.

Table of contents
=================

  * [Localhost and VM Installation](#localhost-and-vm-installation)
  * [GKE Installation](#gke-installation)
  * [MicroK8s Installation](#microk8s-installation)
  * [Client Build](#client-build)

## Localhost and VM installation

#### Dependencies and source code

*Install latest docker compose plugin*:
```bash
$ sudo apt update
$ sudo apt install docker-compose-plugin
```
*Clone the repository (with **--recursive**)*:
```bash
$ mkdir /path/to/your/app && cd /path/to/your/app
$ git clone --recursive https://github.com/SamuelHassine/zotero-prime.git
$ cd zotero-prime
```
*Configure*:
```bash
$ cp .env_example .env
$ cp docker-compose-prod.yml  docker-compose.yml
```
**Edit .env and change DSHOST.**  
For Localhost Installation: DSHOST=http://localhost:8080/   
Fot VM Installation: DSHOST=http://\<VM IP Address\>:8080/  
*Run*:
```bash
$ cp .env_example .env
$ cp docker-compose-prod.yml  docker-compose.yml
```
edit .env and add strong passwords 
```bash
$ sudo docker compose up -d
```
#### Initialize databases
*Initialize databases*:
```bash
$ ./bin/init.sh
$ cd ..
```
*Available endpoints*:

| Name          | URL                                           |
| ------------- | --------------------------------------------- |
| Zotero API    | http://localhost:8080 or  http://\<VM IP Address\>:8080/                        |
| S3            | http://localhost:9000 or http://\<VM IP Address\>:9000/                         |
| PHPMyAdmin    | http://localhost:8083 or http://\<VM IP Address\>:8083/                         |
| S3 Web UI     | http://localhost:9001 or http://\<VM IP Address\>:9001/                         |
| Stream Server | ws://localhost:8081 or ws://\<VM IP Address\>:8081/                         |

*Default login/password*:

| Name          | Login                    | Password           |
| ------------- | ------------------------ | ------------------ |
| Zotero API    | admin                    | admin              |
| S3 Web UI     | zotero                   | zoterodocker       |
| PHPMyAdmin    | root                     | zotero             |

## GKE Installation

*Clone the repository:*
```bash
$ mkdir /path/to/your/app && cd /path/to/your/app
$ git clone https://github.com/uniuuu/zotprime.git
$ git checkout tags/<tag> -b <somename>   
$ cd zotprime
```
*Install Google Cloud SDK: https://cloud.google.com/sdk/docs/install*  
*Install Terraform: https://developer.hashicorp.com/terraform/tutorials/aws-get-started/install-cli*  
*Install Kubectl: https://kubernetes.io/docs/tasks/tools/install-kubectl/*  
*Install Helm: https://helm.sh/docs/intro/install/*  
*Run*:
```bash
$ gcloud init
$ gcloud iam service-accounts create zotprimeprod
$ gcloud projects list
$ gcloud projects add-iam-policy-binding <PROJECT_ID> --member="serviceAccount:NAME@PROJECT_ID.iam.gserviceaccount.com" --role="roles/owner"
```
- <PROJECT_ID> is name of your project ID  
- NAME@PROJECT_ID.iam.gserviceaccount.com is email ID of service account  
```bash
$ cd ./zotprime-k8s/GKE/terraform
$ gcloud iam service-accounts keys create cred.json --iam-account=NAME@PROJECT_ID.iam.gserviceaccount.com
$ mv cred.json ./auth/
$ gcloud services enable container.googleapis.com
$ gcloud services enable cloudresourcemanager.googleapis.com
$ cp terraform.tfvars_example terraform.tfvars
```
**Edit terraform.tfvars and change project_id, region, zones, node-locations, minnode, maxnode, disksize, machine**  
```bash
$ terraform init
$ terraform fmt && terraform validate && terraform plan
$ terraform apply
$ gcloud container clusters get-credentials zotprime-k8s-prod
$ cd ..
```
**Check cluster and install Zotprime Helm Chart**
```bash
$ kubectl config get-contexts
$ kubectl get all --all-namespaces
```
**Edit ./helm-chart/values.yaml and change dsuri:, s3Pointuri:, api:, streamserver:, minios3Data:, phpmyadmin:, minios3Web: .**    
Replace to your hostnames api (**dsuri:**, **api:**), S3 Minio Data (**s3Pointuri:**, **minios3Data:**), Stream Server (**streamserver:**), Phpmyadmin (**phpmyadmin:**) and S3 Minio Web (**minios3Web:**):  
- dsuri: http://api-any.yourhostname.io/  
- s3Pointuri: s3-any.yourhostname.io  
- api: api-any.yourhostname.io  
- streamserver: stream-any.yourhostname.io  
- minios3Data: s3-any.yourhostname.io  
- phpmyadmin: phpmyadmin-any.yourhostname.io  
- minios3Web: minioweb-any.yourhostname.io  
```bash
$ kubectl apply -f zotprime-namespace.yaml
$ helm install zotprime-k8s helm-chart --namespace zotprime
$ kubectl get -A cm,secrets,deploy,rs,sts,pod,pvc,svc,ing
```
*Get Ingress IP's and setup A records in DNS hosting*:  
Wait while GCP will provision IP's verify with below command output in ADDRESS column  
```bash
$ kubectl get -A ing
```
*Available endpoints*:

| Name          | URL                                           |
| ------------- | --------------------------------------------- |
| Zotero API    | http://api-any.yourhostname.io                |
| S3            | http://s3-any.yourhostname.io                 |
| PHPMyAdmin    | http://phpmyadmin-any.yourhostname.io         |
| S3 Web UI     | http://minioweb-any.yourhostname.io           |
| Stream Server | ws://stream-any.yourhostname.io             |

*Default login/password*:

| Name          | Login                    | Password           |
| ------------- | ------------------------ | ------------------ |
| Zotero API    | admin                    | admin              |
| S3 Web UI     | zotero                   | zoterodocker       |
| PHPMyAdmin    | root                     | zotero             |

## MicroK8s Installation


## Client Build

#### Client build Windows and Linux
*Edit and run*:
- For Localhost Install argument's are: 
  ```
  HOST_DS=http://localhost:8080/
  HOST_ST=ws://localhost:9000/
  ```
- For VM Install argument's are:
  ```
  HOST_DS=http://<VM IP Address>:8080/
  HOST_ST=ws://<VM Ip Address:9000/
  ```
- For GKE Install argument's are:
  ```
  HOST_DS=http://api-any.yourhostname.io/
  HOST_ST=ws://stream-any.yourhostname.io/
  ```
- For Argument MLW=[m|w]: w=Windows, l=Linux  

Replace arguments in the respective command below and run it:  
```bash
$ DOCKER_BUILDKIT=1 docker build --progress=plain --file client.Dockerfile \
      --build-arg HOST_DS=http://localhost:8080/ \
      --build-arg HOST_ST=ws://localhost:9000/ \
      --build-arg MLW=l --output build .
```
*Run client*:
```bash
$ ./build/staging/Zotero_VERSION/zotero(.exe))
```
#### Mac client build

*Run*:
```bash
$ git submodule update --init --recursive
$ cd client
$ ./config.sh
$ cd zotero-client
$ npm install
$ npm run build
$ cd ../zotero-standalone-build
$ ./fetch_xulrunner.sh -p m
$ ./fetch_pdftools
$ ./scripts/dir_build -p m
```

*Run client*:
```bash
$ ./staging/Zotero_VERSION/zotero
```
*Connect with the default user and password*:

| Name          | Login                    | Password           |
| ------------- | ------------------------ | ------------------ |
| Zotero        | admin                    | admin              |

![Sync](doc/sync.png)

Credits
1. https://github.com/FiligranHQ/zotprime
2. https://github.com/gfacciol/zotero_dataserver-docker
3. https://github.com/isabekov/dataserver
4. https://github.com/piernov/zotprime
5. https://github.com/Dwarf-Planet-Project/zotero_installation
6. https://github.com/foxsen/zotero-selfhost
7. https://github.com/zehuanli/zotero-selfhost
8. https://github.com/fversaci/zotero-prime