#!/usr/bin/env bash
#
# Bucle de polling de Gmail, para `supervisord` (programa `gmail-poll`).
#
# ¿Por qué un script y no el `command=` en el .conf?
#
# Porque `supervisord.conf` NO es un shell: hace su propia expansión de `%` y
# `$` al leer el archivo, y el resultado no es el que uno espera. Con
# `command=bash -c "... sleep $${GMAIL_POLL_INTERVAL:-120} ..."` el contenedor
# arrancaba y fallaba en bucle con:
#
#     sleep: invalid number '30{GMAIL_POLL_INTERVAL:-120}'
#
# El `$$` no es un escape de `$` en supervisord, y a partir de ahí el resto de
# la expresión queda mangled. La forma correcta de escribir un `$$` en
# supervisord no es documentada en ningún lado útil, y depender de ella convierte el
# arranque del contenedor en una lotería.
#
# Acá no hay una sola variable de shell en el .conf: el `command` es una ruta,
# y todo el `$` vive adentro de este archivo, donde bash lo interpreta con sus
# reglas y sin sorpresas.

set -u

# Por defecto el bucle corre. `GMAIL_POLL_ENABLED=false` lo deja en reposo: el
# proceso sigue vivo (para que `autorestart` no lo relancece en bucle) pero sin
# consultar nada.
#
# Se compara contra "false" y no contra "true" a propósito: así, una variable
# vacía o mal escrita no desactiva el polling por accidente.
if [ "${GMAIL_POLL_ENABLED:-true}" = "false" ]; then
    echo "[gmail-poll] GMAIL_POLL_ENABLED=false · polling desactivado"

    # Un `sleep infinity` sería más claro, pero solo GNU sleep lo acepta: el
    # BSD/macOS responde "usage: sleep number[unit]" y sale con error. Como
    # esto también se corre a mano en un host Mac, el bucle de una hora es
    # igual de válido y no depende de la implementación de `sleep`.
    while true; do
        sleep 3600
    done
fi

# 120 s es el default histórico. 300 (5 min) alcanza de sobra para una demo y
# es lo que pone el `.env.example`.
interval="${GMAIL_POLL_INTERVAL:-120}"

# Si el intervalo no es un número, `sleep` falla en cada vuelta y el bucle se
# convierte en una lluvia de errores. Es mejor arrancar con el default y
# avisar una vez, que no arranca de otra manera.
case "$interval" in
    ''|*[!0-9]*)
        echo "[gmail-poll] GMAIL_POLL_INTERVAL='${interval}' no es un número · usando 120s" >&2
        interval=120
        ;;
esac

echo "[gmail-poll] arrancando · cada ${interval}s"

while true; do
    # `--skip-if-unconfigured`: sin credenciales de Google esto saldría con un
    # error cada ${interval}s, para siempre, y el log dejaría de servir para
    # diagnosticar nada. El flag sale en silencio pero conserva el código de
    # salida, así que un contenedor recién levantado no llena el log de ruido.
    #
    # `|| true` porque el comando devuelve `FAILURE` cuando no está configurado:
    # el bucle tiene que seguir durmiendo y reintentar, no morir.
    php /var/www/html/artisan gmail:sync --no-interaction --skip-if-unconfigured || true

    sleep "$interval"
done