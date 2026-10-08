# Atajos del stack. Ver `docs/` para el detalle de cada uno.
#
# El objetivo de este archivo es que nadie tenga que escribir `--build` por
# costumbre. `docker compose up -d --build` recompila LOS TRES servicios aunque
# no haya cambiado nada, y con el builder de tipo `docker-container` eso implica
# re-exportar la imagen entera como tarball: ~2,3 GB por build, que en una VPN o
# con Docker Desktop lento son 12-22 minutos de espera para obtener exactamente
# la misma imagen. `make up` no reconstruye y arranca en segundos.

COMPOSE := docker compose

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

.PHONY: help up build rebuild down logs ps test builder shell db

help:
	@echo "make up        Levanta el stack SIN reconstruir (usa las imágenes existentes)"
	@echo "make build     Reconstruye las imágenes que cambiaron"
	@echo "make rebuild   Forcea la reconstrucción de las imágenes"
	@echo "make down      Baja los contenedores (conserva los volúmenes)"
	@echo "make logs      Sigue los logs del admin"
	@echo "make test      Corre los tests de Laravel"
	@echo "make builder   Muestra con qué builder va a construir"
	@echo ""
	@echo "Builder detectado: $(if $(DOCKER_BUILDER),$(DOCKER_BUILDER) [driver docker — sin tarball],NINGUNO — usará el activo)"

## Levanta sin reconstruir. Es el comando que se usa el 95% de las veces.
up:
	$(COMPOSE) up -d

## Reconstruye. Docker solo rehace las capas que cambiaron de verdad.
build:
	$(BUILD_ENV) $(COMPOSE) build

## Reconstruye ignorando la caché. Caro: rehace TODO, PaddleOCR incluido.
rebuild:
	$(BUILD_ENV) $(COMPOSE) build --no-cache

down:
	$(COMPOSE) down

logs:
	$(COMPOSE) logs -f admin

ps:
	$(COMPOSE) ps

test:
	cd admin && $(COMPOSE) run --rm --no-deps -T admin php artisan test

builder:
	@docker buildx ls

shell:
	$(COMPOSE) exec admin sh

db:
	$(COMPOSE) exec admin php artisan tinker