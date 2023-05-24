#!/usr/bin/env bash
#   Copyright IBM Corporation 2021
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

# Invoke as ./buildimages.sh <container_runtime>
# Examples:
# 1) ./buildimages.sh
# 2) ./buildimages.sh podman

if [[ "$(basename "$PWD")" != 'scripts' ]] ; then
  echo 'please run this script from the "scripts" directory'
  exit 1
fi
CONTAINER_RUNTIME=docker
if [ "$#" -eq 1 ]; then
    CONTAINER_RUNTIME=$1
fi
if [ "${CONTAINER_RUNTIME}" != "docker" ] && [ "${CONTAINER_RUNTIME}" != "podman" ]; then
   echo 'Unsupported container runtime passed as an argument for building the images: '"${CONTAINER_RUNTIME}"
   exit 1
fi
cd ../../../ # go to the parent directory so that all the relative paths will be correct

echo 'building image zotprime-dataserver'

${CONTAINER_RUNTIME} build -f ds.Dockerfile -t zotprime-dataserver .

echo 'building image zotprime-minio'

${CONTAINER_RUNTIME} build -f minio.Dockerfile -t zotprime-minio .

echo 'building image zotprime-miniomc'

${CONTAINER_RUNTIME} build -f miniomc.Dockerfile -t zotprime-miniomc .

echo 'building image zotprime-tinymceclean'

${CONTAINER_RUNTIME} build -f tmcs.Dockerfile -t zotprime-tinymceclean .

echo 'building image zotprime-streamserver'

${CONTAINER_RUNTIME} build -f sts.Dockerfile -t zotprime-streamserver .

echo 'building image zotprime-db'

${CONTAINER_RUNTIME} build -f db.Dockerfile -t zotprime-db .

echo 'building image zotprime-redis'

${CONTAINER_RUNTIME} build -f r.Dockerfile -t zotprime-redis .

echo 'building image zotprime-localstack'

${CONTAINER_RUNTIME} build -f ls.Dockerfile -t zotprime-localstack .

echo 'building image zotprime-memcached'

${CONTAINER_RUNTIME} build -f m.Dockerfile -t zotprime-memcached .

echo 'building image zotprime-phpmyadmin'

${CONTAINER_RUNTIME} build -f pa.Dockerfile -t zotprime-phpmyadmin .

echo 'building image zotprime-elasticsearch'

${CONTAINER_RUNTIME} build -f es.Dockerfile -t zotprime-elasticsearch .
cd -

echo 'done'
