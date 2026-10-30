#!/bin/sh
# Arranque del contenedor en Render.
# 1. Puerto: el de la variable PORT (Render la define; 10000 si no).
# 2. Usuarios demo: crea o actualiza sus contraseñas con DEMO_PASSWORD (idempotente). Si la base
#    no responde, se registra un aviso y Apache arranca igualmente; se reintenta en el próximo arranque.
# 3. Apache en primer plano.
set -eu

export PORT="${PORT:-10000}"

if ! php /var/www/planificador/bin/crear_usuarios_demo.php; then
    echo "Aviso: no se han podido preparar los usuarios demo; se reintentará en el próximo arranque" >&2
fi

exec apache2-foreground
