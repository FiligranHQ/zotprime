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

echo 'pushing image app-zotero-stream-server'
${CONTAINER_RUNTIME} tag app-zotero-stream-server ${REGISTRY_URL}/${REGISTRY_NAMESPACE}/app-zotero-stream-server
${CONTAINER_RUNTIME} push ${REGISTRY_URL}/${REGISTRY_NAMESPACE}/app-zotero-stream-server

echo 'pushing image app-zotprime'
${CONTAINER_RUNTIME} tag app-zotprime ${REGISTRY_URL}/${REGISTRY_NAMESPACE}/app-zotprime
${CONTAINER_RUNTIME} push ${REGISTRY_URL}/${REGISTRY_NAMESPACE}/app-zotprime

echo 'pushing image app-tinymce-clean-server'
${CONTAINER_RUNTIME} tag app-tinymce-clean-server ${REGISTRY_URL}/${REGISTRY_NAMESPACE}/app-tinymce-clean-server
${CONTAINER_RUNTIME} push ${REGISTRY_URL}/${REGISTRY_NAMESPACE}/app-tinymce-clean-server

echo 'pushing image db-zotprime-minio'
${CONTAINER_RUNTIME} tag db-zotprime-minio ${REGISTRY_URL}/${REGISTRY_NAMESPACE}/db-zotprime-minio
${CONTAINER_RUNTIME} push ${REGISTRY_URL}/${REGISTRY_NAMESPACE}/db-zotprime-minio

echo 'done'
