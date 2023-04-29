:: Copyright IBM Corporation 2021
::
::  Licensed under the Apache License, Version 2.0 (the "License");
::  you may not use this file except in compliance with the License.
::  You may obtain a copy of the License at
::
::        http://www.apache.org/licenses/LICENSE-2.0
::
::  Unless required by applicable law or agreed to in writing, software
::  distributed under the License is distributed on an "AS IS" BASIS,
::  WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
::  See the License for the specific language governing permissions and
::  limitations under the License.

:: Invoke as pushimages.bat <registry_url> <registry_namespace> <container_runtime>
:: Examples:
:: 1) pushimages.bat
:: 2) pushimages.bat quay.io your_quay_username
:: 3) pushimages.bat index.docker.io your_registry_namespace podman

@echo off
IF "%3"=="" GOTO DEFAULT_CONTAINER_RUNTIME
SET CONTAINER_RUNTIME=%3%
GOTO REGISTRY

:DEFAULT_CONTAINER_RUNTIME
    SET CONTAINER_RUNTIME=docker
	GOTO REGISTRY

:REGISTRY
    IF "%2"=="" GOTO DEFAULT_REGISTRY
    IF "%1"=="" GOTO DEFAULT_REGISTRY
    SET REGISTRY_URL=%1
    SET REGISTRY_NAMESPACE=%2
    GOTO DOCKER_CONTAINER_RUNTIME

:DEFAULT_REGISTRY
    SET REGISTRY_URL=localhost:32000
    SET REGISTRY_NAMESPACE=zotprime-k8s
	GOTO DOCKER_CONTAINER_RUNTIME

:DOCKER_CONTAINER_RUNTIME
	IF NOT "%CONTAINER_RUNTIME%" == "docker" GOTO PODMAN_CONTAINER_RUNTIME
	GOTO MAIN

:PODMAN_CONTAINER_RUNTIME
	IF NOT "%CONTAINER_RUNTIME%" == "podman" GOTO UNSUPPORTED_BUILD_SYSTEM
	GOTO MAIN

:UNSUPPORTED_BUILD_SYSTEM
    echo 'Unsupported build system passed as an argument for pushing the images.'
    GOTO SKIP

:MAIN
:: Uncomment the below line if you want to enable login before pushing
:: %CONTAINER_RUNTIME% login %REGISTRY_URL%

echo "pushing image app-zotero-stream-server"
%CONTAINER_RUNTIME% tag app-zotero-stream-server %REGISTRY_URL%/%REGISTRY_NAMESPACE%/app-zotero-stream-server
%CONTAINER_RUNTIME% push %REGISTRY_URL%/%REGISTRY_NAMESPACE%/app-zotero-stream-server

echo "pushing image app-zotprime"
%CONTAINER_RUNTIME% tag app-zotprime %REGISTRY_URL%/%REGISTRY_NAMESPACE%/app-zotprime
%CONTAINER_RUNTIME% push %REGISTRY_URL%/%REGISTRY_NAMESPACE%/app-zotprime

echo "pushing image app-tinymce-clean-server"
%CONTAINER_RUNTIME% tag app-tinymce-clean-server %REGISTRY_URL%/%REGISTRY_NAMESPACE%/app-tinymce-clean-server
%CONTAINER_RUNTIME% push %REGISTRY_URL%/%REGISTRY_NAMESPACE%/app-tinymce-clean-server

echo "pushing image db-zotprime-minio"
%CONTAINER_RUNTIME% tag db-zotprime-minio %REGISTRY_URL%/%REGISTRY_NAMESPACE%/db-zotprime-minio
%CONTAINER_RUNTIME% push %REGISTRY_URL%/%REGISTRY_NAMESPACE%/db-zotprime-minio

echo "done"

:SKIP
