#!/bin/bash
#
# Entrypoint del contenedor admin.
#
# Su trabajo es dejar el árbol de `storage/` en condiciones de escritura para
# `www-data` y ejecutar las migraciones, y después entregarle el control a
# supervisor, que es quien mantiene php-fpm, nginx y la cola vivos.

set -e

APP_ROOT="${APP_ROOT:-/var/www/html}"
DATA_ROOT="${DATA_ROOT:-/var/www/gmail-docs}"

echo "[INIT] Gmail Docs Validator · admin"

cd "$APP_ROOT"

# ── Dependencias de PHP ──────────────────────────────────────────────────────
# Va PRIMERO, antes de tocar nada, porque sin `vendor/` TODO lo que sigue falla
# con el mismo error de PHP y el mismo mensaje: "Failed opening required
# vendor/autoload.php". Correr migraciones para descubrir después que el
# problema eran las dependencias solo hace que el mensaje que ve el operador
# hable de `DB_DATABASE` y los permisos del volumen, que no tienen nada que ver.
if [ ! -f "$APP_ROOT/vendor/autoload.php" ]; then
    echo "[INIT] ERROR: no existe $APP_ROOT/vendor/autoload.php."

    # La causa más común NO es una imagen rota: es un bind-mount del código que
    # tapó el `vendor` que la imagen sí traía. `vendor/` está en el `.dockerignore`
    # justamente para que no viaje desde el host, así que un checkout de prod sin
    # `composer install` encima del mount deja el directorio sin dependencias.
    #
    # Se detecta mirando `/proc/mounts`, que es el único lugar que dice la verdad
    # sobre qué está montado: `ls` de un directorio no lo revela.
    #
    # La comparación es por campo exacto con awk, no con grep: un patrón de
    # coincidencia sobre la línea entera haría que `/` matcheara `//` y que
    # `/var/www` matcheara `/var/www/html`. Con awk, campo 2 es el punto de
    # montaje y se compara con `==`.
    if awk -v p="$APP_ROOT" '$2 == p { f = 1 } END { exit !f }' /proc/mounts 2>/dev/null; then
        echo "[INIT]        Y $APP_ROOT ESTÁ MONTADO desde el host, así que el"
        echo "[INIT]        vendor de la imagen quedó tapado."
        echo "[INIT]        Opciones:"
        echo "[INIT]          a) Correr 'composer install --no-dev' en el host, o"
        echo "[INIT]          b) Sacar el bind-mount del código en el despliegue."
        echo "[INIT]        (La opción b es la recomendada: el .dockerignore"
        echo "[INIT]         excluye vendor/ a propósito, porque el del host está"
        echo "[INIT]         compilado para otra plataforma.)"
    else
        echo "[INIT]        No hay mount sobre $APP_ROOT, así que la imagen se"
        echo "[INIT]        construyó sin dependencias. Reconstruila:"
        echo "[INIT]            docker compose build admin --no-cache"
    fi
    exit 1
fi

# ── Carpetas de storage ─────────────────────────────────────────────────────
# Laravel necesita que estas ocho existan, y varias tienen `.gitignore` dentro
# (por eso git nunca las trae): si falta cualquiera, cualquier artisan falla.
mkdir -p \
    "$APP_ROOT/storage/app/public" \
    "$APP_ROOT/storage/app/attachments" \
    "$APP_ROOT/storage/framework/cache/data" \
    "$APP_ROOT/storage/framework/sessions" \
    "$APP_ROOT/storage/framework/testing" \
    "$APP_ROOT/storage/framework/views" \
    "$APP_ROOT/storage/logs" \
    "$APP_ROOT/bootstrap/cache"

# El service account de Google se monta acá (ver docs/GOOGLE_CLOUD_SETUP.md).
# No viene en la imagen a propósito: es la credencial con más alcance.
mkdir -p "$APP_ROOT/storage/private"

# Volúmenes con datos (SQLite y adjuntos). Ya existen en la imagen, pero un
# volumen nombrado recién creado conserva los permisos del punto de montaje:
# sin este chown, el worker de cola no puede escribir en la base.
mkdir -p "$DATA_ROOT/db" "$DATA_ROOT/attachments"

# `public/storage` es el enlace que sirve los adjuntos marcados como públicos.
# Con nginx, `root` es `public/`, así que el enlace tiene que estar ahí.
if [ ! -e "$APP_ROOT/public/storage" ]; then
    ln -sfn "$APP_ROOT/storage/app/public" "$APP_ROOT/public/storage"
fi

chown -R www-data:www-data "$APP_ROOT/storage" "$DATA_ROOT" "$APP_ROOT/bootstrap/cache" 2>/dev/null || true
chmod -R 775 "$APP_ROOT/storage" "$DATA_ROOT" "$APP_ROOT/bootstrap/cache" 2>/dev/null || true

# ── Base de datos ────────────────────────────────────────────────────────────
# SQLite necesita que el archivo exista ANTES de abrirlo: `touch` evita que
# `migrate` falle en el primer arranque con "unable to open database file".
if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
    DB_FILE="${DB_DATABASE:-$APP_ROOT/database/database.sqlite}"
    mkdir -p "$(dirname "$DB_FILE")"
    [ -f "$DB_FILE" ] || touch "$DB_FILE"
    chown www-data:www-data "$DB_FILE" 2>/dev/null || true
fi

# ── Configuración cacheada ───────────────────────────────────────────────────
# El orden importa: `optimize:clear` vacía el cache store, y en el primer
# arranque la tabla `cache` todavía no existe (la crea la migración 0001_01_01).
# Si va antes de migrar, escupe un "no such table: cache" que asusta sin
# romper nada. Después de migrar, la caché queda limpia de verdad.
if [ "${AUTO_MIGRATE:-true}" = "true" ]; then
    echo "[INIT] Migraciones…"
    php artisan migrate --force --no-interaction || {
        echo "[INIT] ERROR: fallaron las migraciones."
        echo "[INIT]        Causa habitual: DB_DATABASE apunta a una ruta que no"
        echo "[INIT]        existe o no es escribible por www-data."
        echo "[INIT]        El error de arriba dice cuál es."
        exit 1
    }
fi

if [ "${AUTO_SEED:-false}" = "true" ]; then
    echo "[INIT] Seeders de demo (reglas, regex y prompts)…"
    php artisan demo:seed --force --no-interaction || true
fi

# Si la imagen se construyó con un `.env` distinto al de ahora, la caché
# heredada serviría valores viejos (por ejemplo, el STACK_A_URL del build).
php artisan optimize:clear --no-interaction 2>/dev/null || true
php artisan optimize --no-interaction 2>/dev/null || true

# ── Comprobación de dependencias ─────────────────────────────────────────────
# Los workers pueden no estar listos todavía; es mejor avisar en el log que
# fallar el arranque del admin por un problema ajeno.
for stack in a b; do
    url_var="STACK_${stack^^}_URL"
    url="${!url_var:-}"

    if [ -z "$url" ]; then
        continue
    fi

    if curl -fsS --max-time 5 "$url/healthz" > /dev/null 2>&1; then
        echo "[INIT] Worker Stack ${stack^^} OK en $url"
    else
        echo "[INIT] AVISO: el worker Stack ${stack^^} ($url) no responde /healthz todavía."
        echo "[INIT]        El pipeline degradará a Stack A hasta que esté listo."
    fi
done

echo "[INIT] Todo listo. Admin en http://localhost:${ADMIN_PORT:-8000}/admin"

exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf