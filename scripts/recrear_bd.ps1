# Deja la base de desarrollo cargada desde cero: borra el esquema, ejecuta la migración y el seed
# con el usuario propietario (DATABASE_ADMIN_URL) y crea los usuarios demo (DATABASE_URL + DEMO_PASSWORD).
# Uso: .\scripts\recrear_bd.ps1
# BORRA TODOS LOS DATOS de la base de desarrollo. No se usa nunca contra producción.

. (Join-Path $PSScriptRoot 'entorno.ps1')
. (Join-Path $PSScriptRoot 'bd.ps1')

if ($env:APP_ENV -eq 'production') {
    throw 'recrear_bd.ps1 no se puede ejecutar con APP_ENV=production'
}

$raiz = Split-Path -Parent $PSScriptRoot
$admin = Get-ConexionBd 'DATABASE_ADMIN_URL'
$migracion = Join-Path $raiz 'db\migrations\001_esquema.sql'
$seed = Join-Path $raiz 'db\seed.sql'

Write-Host "Recreando la base $($admin.Base) en $($admin.Servidor):$($admin.Puerto)..."

# Todo en una sola transacción (-1): si algo falla, la base queda como estaba.
Invoke-Psql $admin @(
    '-1',
    '-c', 'SET client_min_messages TO warning',
    '-c', 'DROP SCHEMA IF EXISTS public CASCADE',
    '-c', 'CREATE SCHEMA public',
    '-f', $migracion,
    '-f', $seed
) | Out-Null

Write-Host 'Esquema y datos de ejemplo cargados.'

& php (Join-Path $raiz 'bin\crear_usuarios_demo.php')
if ($LASTEXITCODE -ne 0) { throw 'No se han podido crear los usuarios demo' }
