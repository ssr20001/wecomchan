# 使用 alpine 镜像
FROM php:8.2-cli-alpine

# 设置工作目录
WORKDIR /app

# 将核心脚本复制进去
COPY index.php /app/index.php

# 设置时区
ENV TZ=Asia/Shanghai
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

# 暴露 8081 端口
EXPOSE 8081

# 启动 PHP 内置服务器。
CMD ["php", "-S", "0.0.0.0:8081", "/app/index.php"]