FROM composer/composer:latest-bin AS composer
FROM dunglas/frankenphp:1.12.2-builder-php8.5.5-trixie

# 安装php扩展
RUN install-php-extensions \
        pdo_pgsql \
        gd \
        intl \
        zip \
        imagick \
        opcache \
        pcntl \
        sockets \
        bcmath \
        redis

# 安装常用工具
RUN apt-get update && apt-get install -y --no-install-recommends \
    curl \
    ca-certificates \
    vim \
    sqlite3 \
    tzdata \
    locales \
    procps \
    iputils-ping \
    unzip \
    net-tools \
    curl \
    jq \
    ripgrep \
    wget \
    git \
    openssh-client \
    openssh-server \
    && mkdir -p /config/psysh \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# 配置 SSH server
RUN mkdir -p /var/run/sshd \
    && sed -i 's/#PermitRootLogin prohibit-password/PermitRootLogin prohibit-password/' /etc/ssh/sshd_config \
    && sed -i 's/#PasswordAuthentication yes/PasswordAuthentication no/' /etc/ssh/sshd_config \
    && sed -i 's/#KbdInteractiveAuthentication yes/KbdInteractiveAuthentication no/' /etc/ssh/sshd_config \
    && sed -i 's/#PubkeyAuthentication yes/PubkeyAuthentication yes/' /etc/ssh/sshd_config \
    && ssh-keygen -A

# 预置 root 的开发身份配置占位文件；named volume 首次创建时会从镜像复制这些内容
RUN mkdir -p /root/.ssh /root/.config/git \
    && touch /root/.ssh/authorized_keys \
        /root/.ssh/config \
        /root/.ssh/known_hosts \
        /root/.config/git/config \
    && git config --file /root/.config/git/config pull.rebase false \
    && chmod 700 /root/.ssh \
    && chmod 600 /root/.ssh/authorized_keys /root/.ssh/config /root/.config/git/config \
    && chmod 644 /root/.ssh/known_hosts

# 安装 Node.js LTS
RUN curl -fsSL https://deb.nodesource.com/setup_lts.x | bash - \
    && apt-get install -y nodejs \
    && npm install -g npm@latest \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*
RUN npm config set registry https://registry.npmmirror.com

# 安装AI代理工具
RUN npm install -g @anthropic-ai/claude-code
RUN npm install -g @openai/codex
RUN curl https://cursor.com/install -fsS | bash && echo 'export PATH="$HOME/.local/bin:$PATH"' >> ~/.bashrc

# 语言和时区
RUN sed -i 's/# zh_CN.UTF-8 UTF-8/zh_CN.UTF-8 UTF-8/' /etc/locale.gen \
    && locale-gen \
    && update-locale LANG=zh_CN.UTF-8 LC_CTYPE=zh_CN.UTF-8
ENV LANG=zh_CN.UTF-8 \
    LC_ALL=zh_CN.UTF-8 \
    LANGUAGE=zh_CN:zh

# Go 配置
ENV GOPROXY=https://goproxy.cn,direct \
    GO111MODULE=on \
    GOPATH=/root/go \
    GOROOT=/usr/local/go \
    PATH=$PATH:/root/go/bin:/usr/local/go/bin

# 写入系统级环境变量，确保 SSH 登录也能找到 Go
RUN echo 'export GOROOT=/usr/local/go' > /etc/profile.d/go.sh \
    && echo 'export GOPATH=/root/go' >> /etc/profile.d/go.sh \
    && echo 'export PATH=/usr/local/go/bin:/root/go/bin:$PATH' >> /etc/profile.d/go.sh \
    && chmod +x /etc/profile.d/go.sh \
    && cat /etc/profile.d/go.sh >> /root/.bashrc

RUN CGO_ENABLED=0 go install -v -ldflags="-s -w" golang.org/x/tools/gopls@latest
RUN CGO_ENABLED=0 go install -v -ldflags="-s -w" honnef.co/go/tools/cmd/staticcheck@latest
COPY go.mod go.sum ./
RUN go mod download && go mod verify

COPY --from=composer /composer /usr/bin/composer
COPY dev-entrypoint.sh /usr/local/bin/dev-entrypoint

RUN chmod +x /usr/local/bin/dev-entrypoint

CMD ["/usr/local/bin/dev-entrypoint"]
