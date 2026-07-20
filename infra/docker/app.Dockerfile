# 1. Recebe a Golden Image (Passada via --build-arg no Jenkins)
ARG BASE_IMAGE
FROM ${BASE_IMAGE}

# 2. Configurações de Ambiente para o Supervisor (Otimizado para Laravel)
ENV SUPERVISOR_PHP_COMMAND="frankenphp php-server --listen 0.0.0.0:80 --root /var/www/html/public"
ENV SUPERVISOR_PHP_USER="root"


# Define o diretório de trabalho padrão
WORKDIR /var/www/html

# 4. Prepara as dependências (Truque de mestre para o Path Repository)
COPY package.json yarn.lock composer.json composer.lock ./
COPY payshop-sdk/ /var/www/payshop-sdk/

# 5. Instala dependências
RUN yarn install && \
    composer install --ignore-platform-reqs --no-scripts --no-dev --optimize-autoloader

# 6. Copia o restante do código da aplicação
# O .dockerignore deve garantir que .env e pastas desnecessárias não entrem aqui
COPY . .

# 7. Build dos assets e limpeza do Node
RUN yarn build && \
    apt-get purge -y nodejs npm && apt-get autoremove -y

# 8. Ajuste de Permissões Críticas
# Garante que o Laravel consiga escrever em logs e cache
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache && \
    chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# 8. Prepara o Entrypoint e Binários do Supervisor
COPY ./infra/entrypoint.sh /entrypoint.sh
COPY ./infra/start-container /usr/local/bin/start-container
COPY ./infra/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY ./infra/php.ini /usr/local/etc/php/conf.d/custom.ini
RUN chmod +x /entrypoint.sh /usr/local/bin/start-container && \
    mkdir -p /etc/supervisor/conf.d /var/log/supervisor

# 9. Finalização
EXPOSE 80

# O entrypoint gerencia o cache do Laravel e inicia o Apache/Supervisor
ENTRYPOINT ["/entrypoint.sh"]
