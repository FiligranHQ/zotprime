#!/usr/bin/env bash
#   Copyright IBM Corporation 2020
#
#   Licensed under the Apache License, Version 2.0 (the "License");
#   you may not use this file except in compliance with the License.
#   You may obtain a copy of the License at
#
#        http://www.apache.org/licenses/LICENSE-2.0
#
#   Unless required by applicable law or agreed to in writing, software
#   distributed under the License is distributed on an "AS IS" BASIS,
#   WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
#   See the License for the specific language governing permissions and
#   limitations under the License.

# Invoke as ./pushimages.sh <registry_url> <registry_namespace> <container_runtime>
# Examples:
# 1) ./pushimages.sh
# 2) ./pushimages.sh quay.io your_quay_username
# 3) ./pushimages.sh index.docker.io your_registry_namespace podman

REGISTRY_URL=localhost:32000
REGISTRY_NAMESPACE=zotprime-k8s
CONTAINER_RUNTIME=docker
if [ "$#" -gt 1 ]; then
  REGISTRY_URL=$1
  REGISTRY_NAMESPACE=$2
fi
if [ "$#" -eq 3 ]; then
    CONTAINER_RUNTIME=$3
fi
if [ "${CONTAINER_RUNTIME}" != "docker" ] && [ "${CONTAINER_RUNTIME}" != "podman" ]; then
   echo 'Unsupported container runtime passed as an argument for pushing the images: '"${CONTAINER_RUNTIME}"
   exit 1
fi
# Uncomment the below line if you want to enable login before pushing
# ${CONTAINER_RUNTIME} login ${REGISTRY_URL}

echo 'pushing image zotprime-streamserver'
${CONTAINER_RUNTIME} tag zotprime-streamserver ${REGISTRY_URL}/${REGISTRY_NAMESPACE}/zotprime-streamserver
${CONTAINER_RUNTIME} push ${REGISTRY_URL}/${REGISTRY_NAMESPACE}/zotprime-streamserver

echo 'pushing image zotprime-dataserver'
${CONTAINER_RUNTIME} tag zotprime-dataserver ${REGISTRY_URL}/${REGISTRY_NAMESPACE}/zotprime-dataserver
${CONTAINER_RUNTIME} push ${REGISTRY_URL}/${REGISTRY_NAMESPACE}/zotprime-dataserver

echo 'pushing image zotprime-tinymceclean'
${CONTAINER_RUNTIME} tag zotprime-tinymceclean ${REGISTRY_URL}/${REGISTRY_NAMESPACE}/zotprime-tinymceclean
${CONTAINER_RUNTIME} push ${REGISTRY_URL}/${REGISTRY_NAMESPACE}/zotprime-tinymceclean

echo 'pushing image db-zotprime-minio'
${CONTAINER_RUNTIME} tag zotprime-minio ${REGISTRY_URL}/${REGISTRY_NAMESPACE}/zotprime-minio
${CONTAINER_RUNTIME} push ${REGISTRY_URL}/${REGISTRY_NAMESPACE}/zotprime-minio

echo 'done'
