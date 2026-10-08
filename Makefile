# Atajos del stack. Ver `docs/` para el detalle de cada uno.
#
# El objetivo de este archivo es que nadie tenga que escribir `--build` por
# costumbre. `docker compose up -d --build` recompila LOS TRES servicios aunque
# no haya cambiado nada, y con el builder de tipo `docker-container` eso implica
# re-exportar la imagen entera como tarball: ~2,3 GB por build, que en una VPN o
# con Docker Desktop lento son 12-22 minutos de espera para obtener exactamente
# la misma imagen. `make up` no reconstruye y arranca en segundos.

# Desarrollo usa el compose base MÁS el de overrides; producción usa solo el
# base. No es una preferencia: `docker-compose.dev.yml` monta el código del host
# sobre `/var/www/html`, y en un despliegue eso tapa el `vendor/` de la imagen y
# el arranque falla con "Failed opening required vendor/autoload.php".
#
# O sea: la diferencia entre "funciona en tu máquina" y "funciona en prod" es
# exactamente una palabra en la línea de comando.
COMPOSE_DEV  := docker compose -f docker-compose.yml -f docker-compose.dev.yml
COMPOSE_PROD := docker compose -f docker-compose.yml

# El builder con driver `docker` es el que escribe directo en el store de
# Docker. El de tipo `docker-container` (que exporta tarball) tiene otro nombre
# en cada plataforma, así que se DETECTA en vez de hardcodearlo:
#
#   Linux / Docker Engine  →  `default`
#   macOS / Docker Desktop →  `desktop-linux`
#
# El `\*` en la salida de `buildx ls` marca el builder activo, y las filas de
# nodo arrancan con `\_`, así que hay que filtrar ambas.
DOCKER_BUILDER := $(shell docker buildx ls 2>/dev/null | awk '$$2 == "docker" && $$1 !~ /^_/ { print $$1; exit }')

ifeq ($(DOCKER_BUILDER),)
BUILD_ENV :=
else
BUILD_ENV := BUILDX_BUILDER=$(DOCKER_BUILDER)
endif

.PHONY: help up build rebuild down logs ps test builder shell db prod prod-build

help:
	@echo "make up          Levanta el stack de DESARROLLO, sin reconstruir"
	@echo "make build       Reconstruye lo que cambió (desarrollo)"
	@echo "make rebuild     Fuerza la reconstrucción (desarrollo)"
	@echo ""
	@echo "make prod        Levanta el stack de PRODUCCIÓN (sin el mount del código)"
	@echo "make prod-build  Reconstruye las imágenes para producción"
	@echo ""
	@echo "make down        Baja los contenedores (conserva los volúmenes)"
	@echo "make logs        Sigue los logs del admin"
	@echo "make test        Corre los tests de Laravel"
	@echo "make builder     Muestra con qué builder va a construir"
	@echo ""
	@echo "Builder detectado: $(if $(DOCKER_BUILDER),$(DOCKER_BUILDER) [driver docker — sin tarball],NINGUNO — usará el activo)"

## ── Producción ───────────────────────────────────────────────────────────────
# Solo `docker-compose.yml`. El código va en la imagen y las credenciales de
# Google entran por el bind-mount de `./admin/storage/private`.
prod:
	$(BUILD_ENV) $(COMPOSE_PROD) up -d

prod-build:
	$(BUILD_ENV) $(COMPOSE_PROD) build

## Levanta sin reconstruir. Es el comando que se usa el 95% de las veces.
up:
	$(COMPOSE_DEV) up -d

## Reconstruye. Docker solo rehace las capas que cambiaron de verdad.
build:
	$(BUILD_ENV) $(COMPOSE_DEV) build

## Reconstruye ignorando la caché. Caro: rehace TODO, PaddleOCR incluido.
rebuild:
	$(BUILD_ENV) $(COMPOSE_DEV) build --no-cache

down:
	$(COMPOSE_DEV) down

logs:
	$(COMPOSE_DEV) logs -f admin

ps:
	$(COMPOSE_DEV) ps

test:
	cd admin && $(COMPOSE_DEV) run --rm --no-deps -T admin php artisan test

builder:
	@docker buildx ls

shell:
	$(COMPOSE_DEV) exec admin sh

db:
	$(COMPOSE_DEV) exec admin php artisan tinker