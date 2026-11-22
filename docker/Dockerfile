# 官方 PHP 8.5 Alpine 镜像
FROM php:8.5-cli-alpine

# 设置工作目录
WORKDIR /app

# 将核心脚本复制进去
COPY index.php /app/index.php

# 安装 tzdata 并设置时区
ENV TZ=Asia/Shanghai
RUN apk add --no-cache tzdata \
    && ln -snf /usr/share/zoneinfo/$TZ /etc/localtime \
    && echo $TZ > /etc/timezone

# 暴露 8080 端口
EXPOSE 8080

# 关闭原始日志，避免泄露请求 URL。
CMD ["sh", "-c", "exec php -d display_errors=0 -d log_errors=0 -S 0.0.0.0:8080 /app/index.php 2>/dev/null"]
