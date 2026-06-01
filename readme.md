# HelmDesk

一个支持私有化部署的开源AI客服系统，类似 Intercom，专注于简单易用的部署体验。

## 特性

- **一键运行** - 单个二进制文件，无需安装 PHP、Nginx 等依赖
- **自动 HTTPS** - 内置 Let's Encrypt 自动证书管理
- **开箱即用** - 内嵌数据库（SQLite），零配置启动
- **现代技术栈** - Go + Laravel + Vue 3 混合架构
- **轻量化部署** - 二进制文件约 136MB，包含完整应用

## 技术栈

**后端**

- Go - 应用容器和 HTTP 服务
- FrankenPHP - PHP 运行时嵌入
- Laravel - Web 框架和业务逻辑
- SQLite - 数据存储

**前端**

- Vue 3 + Inertia.js - 单页应用
- Tailwind CSS 4 + reka-ui - UI 样式
- Vite - 构建工具

## 快速开始

### 生产部署

1. 下载对应平台的二进制文件

```bash
# 下载到目录，例如
cd /opt/helmdesk
wget -O helmdesk-amd64 https://github.com/shellphy/helmdesk/releases/latest/download/helmdesk-amd64
chmod +x helmdesk-amd64
```

1. 运行（HTTP 模式）

```bash
./helmdesk-amd64 --port=8080
```

访问 [http://localhost:8080](http://localhost:8080)

1. 运行（HTTPS 模式，自动证书）

```bash
# 如果使用 80/443 端口，需要先授权
sudo setcap 'cap_net_bind_service=+ep' helmdesk-amd64

# 启动并自动配置 HTTPS
./helmdesk-amd64 --domain=www.helmdesk.app
```

首次启动会自动申请 SSL 证书，需要几秒钟。访问 [https://www.helmdesk.app](https://www.helmdesk.app)

1. 后台运行

```bash
nohup ./helmdesk-amd64 --domain=www.helmdesk.app >> /tmp/helmdesk.log 2>&1 &
```

### 命令行参数

```bash
artisan tinker                 # 启动 Tinker（可以安装 rlwrap，用 rlwrap ./helmdesk-arm64 artisan tinker 启动有更好的交互效果）
--port=8080                    # 指定 HTTP 端口（默认 80）
--domain=example.com           # 指定域名，自动启用 HTTPS（多个域名用逗号分隔）
--storage-path=/data           # 指定数据存储路径（默认 ./storage）
```

## 开发指南

### 环境要求

- **推荐方式**: VS Code + Dev Containers 扩展
- 或者：Docker & Docker Compose + Go 1.25+

### 方式一：使用 VS Code Dev Container（推荐）

这是最简单的开发方式，提供完整的 IDE 集成和自动化环境配置。

#### 前置要求

1. 安装 [VS Code](https://code.visualstudio.com/)
2. 安装 [Docker Desktop](https://www.docker.com/products/docker-desktop/)
3. 安装 VS Code 扩展：[Dev Containers](https://marketplace.visualstudio.com/items?itemName=ms-vscode-remote.remote-containers)

#### 快速开始

1. 用 VS Code 打开项目文件夹
2. 按 `F1` 或 `Cmd/Ctrl+Shift+P` 打开命令面板
3. 输入并选择 `Dev Containers: Reopen in Container`
4. 等待容器构建和初始化（首次需要几分钟，会自动安装所有依赖插件）
5. 初始化完成后，先安装依赖：

```shell
composer install
npm i
cp .env.example .env
php artisan key:generate
php artisan storage:link
composer dump
php artisan migrate
composer codegen
```

1. 打开一个终端运行 `make run` 启动后端，再开一个终端运行前端：`npm run dev`
2. 访问 [http://localhost](http://localhost)

#### Dev Container 特性

- 自动安装所有开发依赖（Composer、npm、Go modules）
- 自动配置数据库和环境变量
- 内置 PHP、Go、Vue 等语言服务和智能提示
- 持久化缓存（依赖、bash历史等），重建容器不丢失
- 集成调试配置，支持断点调试 PHP 和 Go
- 预配置的任务运行器和代码格式化

#### 常用操作

```bash
# 运行 Laravel Artisan 命令
php artisan migrate
php artisan tinker

# 运行测试
php artisan test

# 格式化代码
npm run format
composer pint

# 清除缓存
php artisan cache:clear
```

#### 填充本地 demo 数据

注册一个测试 workspace 后，执行以下命令向其写入一批 demo 数据，方便查看页面效果（生产必需的 seed 走 `DatabaseSeeder`，与 demo 数据互不影响）：

```bash
php artisan db:seed --class=DemoSeeder
```

单独的 demo seeder 位于 `database/seeders/`，以 `*DemoSeeder` 命名，可按需单独执行。

### 方式二：传统 Docker Compose 方式

1. 启动 Docker 容器

```bash
docker compose up -d dev
```

1. 进入容器并初始化

```bash
docker compose exec dev bash
npm i
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate
```

1. 启动前端开发服务器

```bash
npm run dev
```

1. 新开一个终端，进入容器启动后端

```bash
docker compose exec dev bash
make run
```

访问 [http://localhost](http://localhost)

### 构建二进制文件

```bash
# 构建当前平台
./build-static.sh

# 构建指定平台
./build-static.sh -p amd64    # x86-64
./build-static.sh -p arm64    # ARM64
./build-static.sh -p all      # 所有平台

# 输出位置
# build/helmdesk-amd64
# build/helmdesk-arm64
```

#### Windows 打包

Windows 打包脚本会自动准备 PHP TS 运行时、PHP devel pack、vcpkg 依赖、Composer 生产依赖、前端构建产物，并生成 zip 包。

前置依赖：

- Windows 10/11 x64
- Visual Studio 2026/2022 C++工具链，需包含 MSVC、Windows SDK、C++ Clang tools for Windows
- Go 1.26+
- Node.js 和 npm
- Git、PowerShell，以及可访问 GitHub、windows.php.net、getcomposer.org 的网络环境

默认构建 embedded 包，Laravel/PHP 业务代码会被打进 `helmdesk.exe`，解压后的目录不会暴露 `app/`、`routes/`、`vendor/` 等源码目录：

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\build-windows.ps1
```

如果本机依赖已经安装好、前端也已经构建过，可以跳过耗时步骤快速重打包：

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\build-windows.ps1 -SkipComposer -SkipNpm -SkipFrontend
```

调试时也可以构建展开源码目录的包：

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\build-windows.ps1 -Mode Expanded
```

输出位置：

```text
build/windows/helmdesk-win-x64-embedded/
build/windows/helmdesk-win-x64-embedded.zip
```

运行：

```powershell
cd build\windows\helmdesk-win-x64-embedded
.\helmdesk.exe -port=8080
```

Windows embedded 包仍然需要携带 `php8ts.dll`、`ext/`、`php.ini` 和相关运行时 DLL。首次启动会把内置 Laravel 应用解压到系统临时目录，并初始化 `storage/`、SQLite 数据库和缓存，因此会比后续启动更慢。

默认数据目录是 exe 所在目录下的 `storage/`，可以通过 `--storage-path=D:\helmdesk-data` 指定到其他位置。

## 系统要求

**运行环境**

- Linux (x86-64 或 ARM64)
- 最低 1GB 内存

**支持的操作系统**

- Ubuntu 20.04+
- Debian 11+
- CentOS 8+
- 其他主流 Linux 发行版

## 许可证

本项目基于 **Functional Source License, Version 1.1, Apache 2.0 Future License**（FSL-1.1-Apache-2.0）发布。

简而言之：

- ✅ **允许**：自托管、内部使用、修改源码、二次开发、非商业研究与教育、为客户提供专业服务
- ❌ **不允许**：基于本项目提供与 HelmDesk 功能相同或实质性相似的商业托管服务（即"竞品用途"）
- 🕒 **2 年后自动转 Apache 2.0**：每个版本在发布满 2 年后，自动获得 Apache License 2.0 授权

详见仓库根目录的 [LICENSE](LICENSE) 文件，或访问 [fsl.software](https://fsl.software) 了解协议详情。

需要商业授权（例如希望基于 HelmDesk 提供托管服务）请联系作者。

## 贡献

欢迎提交 Issue 和 Pull Request。

提交代码前请先阅读 [CONTRIBUTING.md](CONTRIBUTING.md)。