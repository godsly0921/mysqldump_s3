# 使用官方 PHP 7.4 CLI 基底映像
FROM php:7.4-cli

# 維護者資訊（可選）
LABEL maintainer="yourname@example.com"

# 安裝系統工具與 PHP 擴充套件
RUN apt-get update && apt-get install -y \
    unzip \
    zip \
    git \
    libzip-dev \
    && docker-php-ext-install pdo_mysql mysqli zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# 設定系統與 PHP 時區為 Asia/Taipei
ENV TZ=Asia/Taipei
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone
RUN echo "date.timezone=$TZ" > /usr/local/etc/php/conf.d/timezone.ini

# 安裝 Composer
# COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# 設定工作目錄
WORKDIR /app

# 將程式碼複製進容器
COPY . /app

# 安裝 PHP 依賴套件
# RUN composer install --no-dev --optimize-autoloader

# 建立 dumps 目錄（避免權限問題）
RUN mkdir -p /app/dumps && chmod -R 755 /app/dumps

# 預設執行指令（你可改成 cron job 或 entrypoint script）
CMD ["php", "/app/main.php"]
