# Prepara la sesión actual de PowerShell para trabajar en el proyecto.
# Uso (con el punto delante, para que afecte a la sesión): . .\scripts\entorno.ps1
#
# - Carga las variables de .env en la sesión.
# - Antepone al PATH, solo en esta sesión, PHP 8.3, el bin de PostgreSQL y el bash de Git.
# No modifica el PATH del sistema ni el PHP de XAMPP.

$raizProyecto = Split-Path -Parent $PSScriptRoot
$archivoEnv = Join-Path $raizProyecto '.env'

if (-not (Test-Path $archivoEnv)) {
    throw "No existe .env. Créalo a partir del ejemplo: Copy-Item .env.example .env"
}

$numeroLinea = 0
foreach ($linea in Get-Content $archivoEnv -Encoding UTF8) {
    $numeroLinea++
    $linea = $linea.Trim()
    if ($linea -eq '' -or $linea.StartsWith('#')) { continue }

    $posicion = $linea.IndexOf('=')
    if ($posicion -lt 1) {
        # No se muestra la línea para no imprimir un posible secreto.
        throw ".env: la línea $numeroLinea no tiene el formato NOMBRE=valor"
    }
    $nombre = $linea.Substring(0, $posicion).Trim()
    $valor = $linea.Substring($posicion + 1).Trim()
    Set-Item -Path "Env:$nombre" -Value $valor
}

$carpetaPhp = 'C:\php83'
$carpetaPostgres = if ($env:PG_BIN) { $env:PG_BIN } else { 'C:\Program Files\PostgreSQL\16\bin' }
$carpetaGit = 'C:\Program Files\Git\bin'

$requisitos = @(
    @{ Ruta = (Join-Path $carpetaPhp 'php.exe'); Mensaje = "No se encuentra PHP 8.3 en $carpetaPhp" },
    @{ Ruta = (Join-Path $carpetaPostgres 'pg_isready.exe'); Mensaje = "No se encuentra PostgreSQL en $carpetaPostgres (revisa PG_BIN en .env)" },
    @{ Ruta = (Join-Path $carpetaGit 'bash.exe'); Mensaje = "No se encuentra el bash de Git for Windows en $carpetaGit" }
)
foreach ($requisito in $requisitos) {
    if (-not (Test-Path $requisito.Ruta)) { throw $requisito.Mensaje }
}

# Quita entradas repetidas por si el script se carga varias veces en la misma sesión.
$nuevas = @($carpetaPhp, $carpetaPostgres, $carpetaGit)
$resto = $env:Path -split ';' | Where-Object { $_ -ne '' -and $nuevas -notcontains $_ }
$env:Path = (@($nuevas) + @($resto)) -join ';'

# Sin comillas dobles en el argumento: PowerShell 5.1 las elimina al llamar a programas externos.
$versionPhp = & php -r 'echo PHP_VERSION;'
if (-not "$versionPhp".StartsWith('8.3.')) {
    throw "Se esperaba PHP 8.3 y se está usando $versionPhp"
}

Write-Host "Entorno cargado: PHP $versionPhp, PostgreSQL en $carpetaPostgres, APP_ENV=$env:APP_ENV"
