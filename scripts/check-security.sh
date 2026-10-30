#!/usr/bin/env bash
# Comprobaciones estáticas de seguridad (CLAUDE.md, apartado 3).
# Sale con código 1 si encuentra algún problema. No se debe desactivar ninguna comprobación.

set -u

cd "$(dirname "$0")/.." || exit 2

fallos=0

informar() {
    echo "FALLO: $1"
    echo "$2" | sed 's/^/    /'
    fallos=$((fallos + 1))
}

if ! git rev-parse --is-inside-work-tree > /dev/null 2>&1; then
    echo "FALLO: no es un repositorio git"
    exit 1
fi

# Archivos del proyecto: versionados y nuevos no ignorados (así se revisan antes del primer commit).
archivos_proyecto() {
    git ls-files -z --cached --others --exclude-standard
}

# 1. DOM inseguro en public/js/
if [ -d public/js ]; then
    salida=$(grep -rnE --include='*.js' 'innerHTML|outerHTML|insertAdjacentHTML|document\.write|eval[[:space:]]*\(|new[[:space:]]+Function' public/js)
    [ -n "$salida" ] && informar "API de DOM o evaluación prohibida en public/js" "$salida"
fi

# 2. SQL con interpolación o concatenación de variables en src/ y public/*.php
rutas_php=()
[ -d src ] && rutas_php+=(src)
for f in public/*.php; do [ -f "$f" ] && rutas_php+=("$f"); done

if [ ${#rutas_php[@]} -gt 0 ]; then
    # Patrones literales (heredoc entre comillas: bash no interpreta $ ni \). Por orden:
    # - cadena "..." con una variable dentro de query/prepare/exec;
    # - concatenación con una variable dentro de query/prepare/exec (dos formas);
    # - sentencia SQL entre comillas dobles con una variable interpolada;
    # - sentencia SQL entre comillas simples concatenada con una variable.
    mapfile -t patrones_sql <<'FIN'
(query|prepare|exec)[[:space:]]*\([[:space:]]*"[^"]*\$
(query|prepare|exec)[[:space:]]*\(.*["'][[:space:]]*\.[[:space:]]*\$
(query|prepare|exec)[[:space:]]*\([[:space:]]*\$[A-Za-z_]+[[:space:]]*\.
"[[:space:]]*(SELECT|INSERT|UPDATE|DELETE|WITH)[[:space:]][^"]*\$
'[[:space:]]*(SELECT|INSERT|UPDATE|DELETE|WITH)[[:space:]][^']*'[[:space:]]*\.[[:space:]]*\$
FIN
    for patron in "${patrones_sql[@]}"; do
        salida=$(grep -rnE --include='*.php' "$patron" "${rutas_php[@]}")
        [ -n "$salida" ] && informar "SQL construido con variables (usa sentencias preparadas)" "$salida"
    done
fi

# 3. Secretos en archivos del proyecto (salvo .env.example)
mapfile -t patrones_secretos <<'FIN'
password[[:space:]]*=[[:space:]]*["'][^"']+
DATABASE_URL=postgres(ql)?://[^ ]+:[^@ ]+@
sk-[A-Za-z0-9_-]{20,}
AKIA[0-9A-Z]{16}
FIN
for patron in "${patrones_secretos[@]}"; do
    salida=$(archivos_proyecto | grep -zv '^\.env\.example$' | xargs -0 -r grep -nIiE -- "$patron" 2> /dev/null)
    [ -n "$salida" ] && informar "posible secreto en el repositorio" "$salida"
done

# 4. .env versionado
salida=$(git ls-files .env)
[ -n "$salida" ] && informar ".env está versionado en git" "$salida"

# 5. display_errors activado en la configuración versionada (este script queda excluido)
patron_display=$(cat <<'FIN'
display_errors["']?[[:space:]]*(=|,)[[:space:]]*["']?(on|1|true|stdout|stderr)\b
FIN
)
salida=$(archivos_proyecto | grep -zEv '^(scripts/check-security\.sh|CLAUDE\.md|docs/.*)$' | xargs -0 -r grep -nIiE -- "$patron_display" 2> /dev/null)
[ -n "$salida" ] && informar "display_errors activado" "$salida"

if [ "$fallos" -gt 0 ]; then
    echo "check-security: $fallos problema(s) encontrado(s)"
    exit 1
fi

echo "check-security: sin problemas"
exit 0
