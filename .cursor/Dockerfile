FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        git \
        docker.io \
        docker-compose-v2 \
        fuse-overlayfs \
        iptables \
        php-cli \
        php-curl \
        php-mbstring \
        php-mysql \
        php-xml \
        php-zip \
        php-phar \
        unzip \
    && rm -rf /var/lib/apt/lists/* \
    && usermod -aG docker ubuntu

WORKDIR /workspace
