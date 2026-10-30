# Prepara PostgreSQL local para el proyecto. Se puede ejecutar varias veces.
# Uso: .\scripts\crear_bases.ps1 [-Superusuario postgres]
#
#  1. Crea los roles de DATABASE_ADMIN_URL (propietario, para migraciones) y DATABASE_URL
#     (aplicación), o actualiza su contraseña si ya existen. Ninguno es superusuario.
#  2. Crea las bases de desarrollo y de test si no existen, en UTF-8.
#  3. Hace al propietario dueño de las dos bases.
#
# Se conecta como superusuario. Su contraseña se pide por consola (o se toma de PGPASSWORD si ya
# está definida en la sesión) y no se guarda en ningún archivo.

param([string]$Superusuario = 'postgres')

. (Join-Path $PSScriptRoot 'entorno.ps1')
. (Join-Path $PSScriptRoot 'bd.ps1')

$admin = Get-ConexionBd 'DATABASE_ADMIN_URL'
$adminTest = Get-ConexionBd 'DATABASE_ADMIN_URL_TEST'
$app = Get-ConexionBd 'DATABASE_URL'
$appTest = Get-ConexionBd 'DATABASE_URL_TEST'

# Los roles son comunes a todas las bases: cada pareja de URL debe usar el mismo usuario y clave.
if ($admin.Usuario -ne $adminTest.Usuario -or $admin.Clave -ne $adminTest.Clave) {
    throw 'DATABASE_ADMIN_URL y DATABASE_ADMIN_URL_TEST deben usar el mismo usuario y contraseña'
}
if ($app.Usuario -ne $appTest.Usuario -or $app.Clave -ne $appTest.Clave) {
    throw 'DATABASE_URL y DATABASE_URL_TEST deben usar el mismo usuario y contraseña'
}
if ($admin.Usuario -eq $app.Usuario) {
    throw 'El usuario de DATABASE_ADMIN_URL debe ser distinto del de DATABASE_URL'
}
if ($admin.Base -ne $app.Base -or $adminTest.Base -ne $appTest.Base) {
    throw 'Las URL de administración y de aplicación deben apuntar a las mismas bases'
}
if (-not $appTest.Base.EndsWith('_test') -or $app.Base -eq $appTest.Base) {
    throw 'La base de DATABASE_URL_TEST debe acabar en _test y ser distinta de la de desarrollo'
}
foreach ($nombre in $admin.Usuario, $app.Usuario, $app.Base, $appTest.Base) {
    if ($nombre -notmatch '^[a-z_][a-z0-9_]*$') {
        throw "Nombre no válido en las URL de .env: usa solo minúsculas, números y _"
    }
}

$clave = $env:PGPASSWORD
if (-not $clave) {
    $segura = Read-Host "Contraseña del superusuario $Superusuario de PostgreSQL" -AsSecureString
    $puntero = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($segura)
    $clave = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($puntero)
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($puntero)
}
# Ojo: PowerShell no distingue mayúsculas en los nombres de variable; este nombre no debe coincidir con el parámetro.
$conexionSuper = [pscustomobject]@{
    Usuario = $Superusuario; Clave = $clave; Servidor = $admin.Servidor; Puerto = $admin.Puerto; Base = 'postgres'
}

# Los valores llegan a psql como variables (\getenv) y se citan con format(%I, %L): nunca se concatenan.
$sqlRol = @'
\getenv usuario ROL_USUARIO
\getenv clave ROL_CLAVE
SELECT format('CREATE ROLE %I LOGIN PASSWORD %L', :'usuario', :'clave')
 WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = :'usuario') \gexec
SELECT format('ALTER ROLE %I WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE PASSWORD %L', :'usuario', :'clave') \gexec
'@

$sqlBase = @'
\getenv base BD_NOMBRE
\getenv propietario BD_PROPIETARIO
SELECT format('CREATE DATABASE %I OWNER %I ENCODING ''UTF8'' TEMPLATE template0', :'base', :'propietario')
 WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = :'base') \gexec
SELECT format('ALTER DATABASE %I OWNER TO %I', :'base', :'propietario') \gexec
'@

$sqlResumen = @'
\getenv base1 BD_DESARROLLO
\getenv base2 BD_TEST
SELECT datname || ' (propietario: ' || pg_get_userbyid(datdba) || ', ' || pg_encoding_to_char(encoding) || ')'
  FROM pg_database WHERE datname IN (:'base1', :'base2') ORDER BY datname;
'@

foreach ($rol in $admin, $app) {
    Invoke-Psql $conexionSuper @() $sqlRol @{ ROL_USUARIO = $rol.Usuario; ROL_CLAVE = $rol.Clave }
    Write-Host "Rol preparado: $($rol.Usuario)"
}

foreach ($base in $app.Base, $appTest.Base) {
    Invoke-Psql $conexionSuper @() $sqlBase @{ BD_NOMBRE = $base; BD_PROPIETARIO = $admin.Usuario }
}

$resumen = Invoke-Psql $conexionSuper @('-tA') $sqlResumen @{ BD_DESARROLLO = $app.Base; BD_TEST = $appTest.Base }
Write-Host "Bases existentes:"
$resumen | ForEach-Object { Write-Host "  $_" }
