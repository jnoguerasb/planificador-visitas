# Funciones comunes para los scripts de base de datos. Se carga con: . (Join-Path $PSScriptRoot 'bd.ps1')
#
# Las contraseñas se pasan a psql por variables de entorno (PGPASSWORD) solo durante la llamada,
# nunca en la línea de comandos ni en archivos.

# Lee una URL postgres://usuario:clave@host:puerto/base de una variable de entorno.
# Si la clave tiene caracteres especiales (@ : / # ?), debe ir codificada en la URL (%40, %3A...).
function Get-ConexionBd([string]$Variable) {
    $url = [Environment]::GetEnvironmentVariable($Variable)
    if (-not $url) { throw "Falta la variable $Variable en .env" }
    if ($url -notmatch '^postgres(ql)?://') { throw "$Variable debe empezar por postgres://" }

    $uri = [Uri]$url
    $posicion = $uri.UserInfo.IndexOf(':')
    if ($posicion -lt 1) { throw "$Variable debe incluir usuario y contraseña (postgres://usuario:clave@...)" }

    $base = $uri.AbsolutePath.Trim('/')
    if (-not $base) { throw "$Variable debe incluir el nombre de la base (.../planificador)" }

    [pscustomobject]@{
        Usuario  = [Uri]::UnescapeDataString($uri.UserInfo.Substring(0, $posicion))
        Clave    = [Uri]::UnescapeDataString($uri.UserInfo.Substring($posicion + 1))
        Servidor = $uri.Host
        Puerto   = if ($uri.Port -gt 0) { $uri.Port } else { 5432 }
        Base     = [Uri]::UnescapeDataString($base)
    }
}

# Ejecuta psql con la conexión indicada. $Entrada (opcional) se envía por la entrada estándar.
# $Variables (opcional) se ponen como variables de entorno durante la llamada (para \getenv).
function Invoke-Psql($Conexion, [string[]]$Argumentos, [string]$Entrada = '', [hashtable]$Variables = @{}) {
    # Sin estos datos psql usaría el usuario de Windows: mejor fallar con un mensaje claro.
    foreach ($campo in 'Usuario', 'Clave', 'Servidor', 'Puerto', 'Base') {
        if (-not $Conexion.$campo) { throw "Conexión incompleta para psql: falta $campo" }
    }

    $temporales = @{
        PGHOST     = $Conexion.Servidor
        PGPORT     = "$($Conexion.Puerto)"
        PGUSER     = $Conexion.Usuario
        PGPASSWORD = $Conexion.Clave
        PGDATABASE = $Conexion.Base
    }
    foreach ($nombre in $Variables.Keys) { $temporales[$nombre] = $Variables[$nombre] }

    $anteriores = @{}
    foreach ($nombre in $temporales.Keys) {
        $anteriores[$nombre] = [Environment]::GetEnvironmentVariable($nombre)
        Set-Item -Path "Env:$nombre" -Value $temporales[$nombre]
    }

    try {
        if ($Entrada) {
            $Entrada | & psql -X -q -w -v ON_ERROR_STOP=1 @Argumentos
        } else {
            & psql -X -q -w -v ON_ERROR_STOP=1 @Argumentos
        }
        if ($LASTEXITCODE -ne 0) { throw "psql ha terminado con error (código $LASTEXITCODE)" }
    }
    finally {
        foreach ($nombre in $anteriores.Keys) {
            if ($null -eq $anteriores[$nombre]) {
                Remove-Item -Path "Env:$nombre" -ErrorAction SilentlyContinue
            } else {
                Set-Item -Path "Env:$nombre" -Value $anteriores[$nombre]
            }
        }
    }
}
