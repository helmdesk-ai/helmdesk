#!/bin/bash
# 构建多平台静态二进制

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
OUTPUT_DIR="${REPO_ROOT}/build/output"
cd "${REPO_ROOT}"

PLATFORM="all"
while getopts "p:" option; do
    case "${option}" in
        p)
            PLATFORM="${OPTARG}"
            ;;
        *)
            echo "用法: $0 [-p amd64|arm64|all]"
            exit 1
            ;;
    esac
done

if [[ "${PLATFORM}" != "amd64" && "${PLATFORM}" != "arm64" && "${PLATFORM}" != "all" ]]; then
    echo "不支持的平台: ${PLATFORM}"
    echo "用法: $0 [-p amd64|arm64|all]"
    exit 1
fi

echo "开始构建 HelmDesk 静态二进制..."
echo ""

# 确保 buildx builder 存在
if ! docker buildx inspect multiarch-builder &> /dev/null; then
    echo "创建 multiarch-builder..."
    docker buildx create --name multiarch-builder --use
    docker buildx inspect --bootstrap
else
    echo "使用已存在的 multiarch-builder"
    docker buildx use multiarch-builder
fi

# GITHUB_TOKEN
BUILD_ARGS=""
if [ -n "${GITHUB_TOKEN}" ]; then
    BUILD_ARGS="--build-arg GITHUB_TOKEN=${GITHUB_TOKEN}"
fi

echo ""
echo "================================"
if [[ "${PLATFORM}" == "all" ]]; then
    echo "构建平台: linux/amd64,linux/arm64"
else
    echo "构建平台: linux/${PLATFORM}"
fi
echo "================================"
echo ""

# 提取二进制文件
mkdir -p "${OUTPUT_DIR}"

build_platform() {
    local arch="$1"
    local docker_platform="$2"
    local source_binary="$3"
    local output_binary="$4"

    echo ""
    echo "构建 ${docker_platform} 镜像..."
    docker buildx build \
        ${BUILD_ARGS} \
        --platform "${docker_platform}" \
        -t "helmdesk-static-builder:${arch}" \
        -f build/Dockerfile \
        --load \
        .

    echo ""
    echo "提取 ${docker_platform} 二进制..."
    docker rm "helmdesk-static-${arch}" >/dev/null 2>&1 || true
    docker create --name "helmdesk-static-${arch}" "helmdesk-static-builder:${arch}"
    docker cp "helmdesk-static-${arch}:/work/dist/${source_binary}" "${OUTPUT_DIR}/${output_binary}"
    docker rm "helmdesk-static-${arch}"
}

if [[ "${PLATFORM}" == "amd64" || "${PLATFORM}" == "all" ]]; then
    build_platform "amd64" "linux/amd64" "helmdesk-linux-x86_64" "helmdesk-amd64"
fi

if [[ "${PLATFORM}" == "arm64" || "${PLATFORM}" == "all" ]]; then
    build_platform "arm64" "linux/arm64" "helmdesk-linux-aarch64" "helmdesk-arm64"
fi

echo ""
echo "========================================"
echo "构建完成！"
echo "========================================"
echo ""
echo "二进制文件:"
ls -lh "${OUTPUT_DIR}"/helmdesk-*
echo ""
file "${OUTPUT_DIR}"/helmdesk-*
echo ""
echo "测试运行："
echo "chmod +x build/output/helmdesk-amd64 (或 build/output/helmdesk-arm64)"
echo "./build/output/helmdesk-amd64 (或 ./build/output/helmdesk-arm64)"
echo ""
