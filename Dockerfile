# Etapa 1: genereaza produse.csv + imaginile cu build_seed.py
# (Docker refoloseste cache-ul daca build_seed.py nu s-a schimbat)
FROM python:3.12-slim AS seed
RUN apt-get update \
 && apt-get install -y --no-install-recommends fonts-dejavu-core \
 && rm -rf /var/lib/apt/lists/* \
 && pip install --no-cache-dir pillow==11.0.0
WORKDIR /build
COPY build_seed.py .
RUN SEED_OUT=/seed python build_seed.py

# Etapa 2: PrestaShop 8 + seed + scriptul de sincronizare
FROM prestashop/prestashop:8-apache
COPY --from=seed /seed /seed
COPY docker/sync.php /seed/sync.php
# init-scripts ruleaza la fiecare pornire a containerului, dupa instalare
COPY --chmod=755 docker/10-sync.sh /tmp/init-scripts/10-sync.sh
