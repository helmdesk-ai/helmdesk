#!/bin/bash
# 构建多平台镜像

if ! docker buildx inspect multiarch-builder &> /dev/null; then
    echo "创建 multiarch-builder..."
    docker buildx create --name multiarch-builder --use
    docker buildx inspect --bootstrap
else
    echo "使用已存在的 multiarch-builder"
    docker buildx use multiarch-builder
fi

docker buildx build \
    --platform linux/amd64,linux/arm64 \
    -t registry.cn-hangzhou.aliyuncs.com/helmdesk/dev:latest \
    -f dev.Dockerfile \
    --push \
    .
