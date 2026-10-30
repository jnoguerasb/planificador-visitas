# Arranca el servidor de desarrollo en http://localhost:8081 sirviendo solo public/.
# Uso: .\scripts\servidor.ps1 [-Puerto 8082]   (Ctrl+C para pararlo)
# El 8080 no se usa porque en el equipo de desarrollo lo ocupa otro programa.

param([ValidateRange(1024, 65535)][int]$Puerto = 8081)

. (Join-Path $PSScriptRoot 'entorno.ps1')

$carpetaPublica = Join-Path (Split-Path -Parent $PSScriptRoot) 'public'
& php -d display_errors=0 -S "localhost:$Puerto" -t $carpetaPublica
