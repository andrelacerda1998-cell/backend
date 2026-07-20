FROM dunglas/frankenphp:php8.3

# Instalação de dependências do sistema
RUN apt-get update && apt-get install -y \
    supervisor \
    nodejs \
    npm \
    zip \
    libicu-dev \
    libjpeg-dev \
    libpng-dev \
    libwebp-dev \
    libfreetype6-dev \
    libzip-dev \
    cron \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# 2. Configuração e Instalação da extensão GD (Essencial para imagens)
# Aqui dizemos ao PHP onde encontrar as bibliotecas de JPEG e FreeType antes de instalar
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install gd

# Extensões PHP e Composer
RUN pecl install redis && docker-php-ext-enable redis && \
    docker-php-ext-install pdo_mysql pdo intl pcntl opcache

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
RUN npm install -g yarn