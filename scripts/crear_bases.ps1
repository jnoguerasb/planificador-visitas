# Crea las bases planificador y planificador_test si no existen. Se puede ejecutar varias veces.
# Uso: .\scripts\crear_bases.ps1 [-Usuario postgres]
#
# Se conecta como superusuario. La contraseña se pide por consola (o se toma de PGPASSWORD si ya
# está definida en la sesión) y no se guarda en ningún archivo.

param([string]$Usuario = 'postgres')

. (Join-Path $PSScriptRoot 'entorno.ps1')

$contrasenaPuesta = $false
if (-not $env:PGPASSWORD) {
    $segura = Read-Host "Contraseña del usuario $Usuario de PostgreSQL" -AsSecureString
    $puntero = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($segura)
    $env:PGPASSWORD = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($puntero)
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($puntero)
    $contrasenaPuesta = $true
}

try {
    foreach ($base in 'planificador', 'planificador_test') {
        # El nombre va como variable de psql (:'base'), sin concatenarlo en el SQL.
        $sql = "SELECT format('CREATE DATABASE %I ENCODING ''UTF8'' TEMPLATE template0', :'base') " +
               "WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = :'base') \gexec"
        $sql | & psql -h localhost -U $Usuario -d postgres -v ON_ERROR_STOP=1 -v "base=$base" -q
        if ($LASTEXITCODE -ne 0) { throw "No se ha podido crear la base $base" }
    }

    $existentes = "SELECT datname FROM pg_database WHERE datname IN ('planificador', 'planificador_test') ORDER BY datname" |
        & psql -h localhost -U $Usuario -d postgres -v ON_ERROR_STOP=1 -tA
    if ($LASTEXITCODE -ne 0) { throw "No se ha podido consultar la lista de bases" }

    Write-Host "Bases existentes: $($existentes -join ', ')"
}
finally {
    if ($contrasenaPuesta) { Remove-Item Env:PGPASSWORD }
}
