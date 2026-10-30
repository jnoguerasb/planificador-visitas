# Arranca el servidor de desarrollo en http://localhost:8080 sirviendo solo public/.
# Uso: .\scripts\servidor.ps1 [-Puerto 8081]   (Ctrl+C para pararlo)

param([ValidateRange(1024, 65535)][int]$Puerto = 8080)

. (Join-Path $PSScriptRoot 'entorno.ps1')

$carpetaPublica = Join-Path (Split-Path -Parent $PSScriptRoot) 'public'
& php -d display_errors=0 -S "localhost:$Puerto" -t $carpetaPublica
