#!/bin/bash
# Colors
GREEN="\033[0;32m"
BLUE="\033[0;34m"
NC="\033[0m"

cd /var/www/html

echo -e "${BLUE}Configurando ambiente: ${APP_ENV}${NC}"

# Comandos comuns a ambos os ambientes
php artisan cache:clear
php artisan config:clear
php artisan migrate --force

php artisan scout:sync-index-settings
php artisan scout:import "App\Models\VendorScheduleSearch"
php artisan scout:import "App\Models\Vendor"
if [ "$APP_ENV" = "production" ]; then
    echo -e "${BLUE}Executando rotinas de PRODUÇÃO...${NC}"
    php artisan route:clear
    php artisan optimize:clear
    php artisan filament:optimize

    # Verifica link de storage sem dar erro
    [ ! -L public/storage ] && php artisan storage:link

    php artisan schedule-monitor:sync

    echo -e "${BLUE}Reset opcache${NC}"
    php -r "opcache_reset();"

    # echo -e "${GREEN}Iniciando servidor Apache (Produção)...${NC}"
    # exec apache2-foreground
    exec start-container
else
    echo -e "${BLUE}Executando rotinas de DESENVOLVIMENTO (Sail)...${NC}"

    # No Dev, geralmente queremos o link de storage sempre
    php artisan storage:link --force

    echo -e "${GREEN}Iniciando start-container (Development)...${NC}"
    exec start-container
fi
