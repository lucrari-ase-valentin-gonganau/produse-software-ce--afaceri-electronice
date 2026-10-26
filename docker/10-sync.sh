#!/bin/sh
# Ruleaza la fiecare pornire (din /tmp/init-scripts): aplica configurarile si produsele din /seed.
# O eroare aici nu opreste magazinul, doar se afiseaza in log.

cd /var/www/html || exit 0

if [ ! -f ./app/config/parameters.php ]; then
    echo "\n* [sync] PrestaShop nu este instalat, sar peste sincronizare"
    exit 0
fi

# install.lock ramas = instalarea a fost intrerupta (ex. restart VPS), baza de date e incompleta
if [ -f ./install.lock ]; then
    echo "\n* [sync] ATENTIE: instalare intrerupta (install.lock exista). Ruleaza ./deploy.sh --reset"
    exit 0
fi

echo "\n* [sync] Aplic configurarile si produsele..."
if runuser -u www-data -- php -d memory_limit=512M /seed/sync.php; then
    echo "\n* [sync] Gata"
else
    echo "\n* [sync] ATENTIE: sincronizarea a esuat, vezi mesajele de mai sus"
fi

# cache-ul Symfony/Smarty se reface la prima cerere
rm -rf ./var/cache/prod ./var/cache/dev
exit 0
