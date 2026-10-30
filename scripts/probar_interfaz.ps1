# Prueba rápida de la interfaz con Edge sin ventana (headless): carga la página, deja que se
# ejecuten los módulos JavaScript y comprueba que se muestra el login y que la consola no tiene
# errores (sintaxis, módulos que no cargan o bloqueos de la Content-Security-Policy).
# Uso: .\scripts\probar_interfaz.ps1        (necesita Microsoft Edge; no forma parte de composer verify)

param([ValidateRange(1024, 65535)][int]$Puerto = 8098)

. (Join-Path $PSScriptRoot 'entorno.ps1')

$edge = @("${env:ProgramFiles(x86)}\Microsoft\Edge\Application\msedge.exe", "$env:ProgramFiles\Microsoft\Edge\Application\msedge.exe") |
    Where-Object { Test-Path $_ } | Select-Object -First 1
if (-not $edge) { throw 'No se encuentra Microsoft Edge' }

$publica = Join-Path (Split-Path -Parent $PSScriptRoot) 'public'
$perfil = Join-Path $env:TEMP "planificador_edge_$PID"
$dom = Join-Path $env:TEMP "planificador_dom_$PID.html"
$consola = Join-Path $env:TEMP "planificador_consola_$PID.txt"

$servidor = Start-Process -FilePath php -ArgumentList '-d', 'display_errors=0', '-S', "localhost:$Puerto", '-t', $publica -PassThru -WindowStyle Hidden
try {
    Start-Sleep -Milliseconds 1200
    # Edge es una aplicación de ventana: su salida solo se recoge bien redirigiéndola con cmd.
    cmd /c "`"$edge`" --headless --disable-gpu --no-first-run --user-data-dir=`"$perfil`" --virtual-time-budget=8000 --enable-logging=stderr --v=0 --dump-dom http://localhost:$Puerto/ > `"$dom`" 2> `"$consola`""

    $html = Get-Content $dom -Raw -Encoding UTF8
    $problemas = @()
    if ($html -notmatch '<p id="cargando"[^>]*hidden') { $problemas += 'La página se ha quedado en "Cargando…": el JavaScript no se ha ejecutado' }
    if ($html -match '<section id="vista-login"[^>]*hidden') { $problemas += 'No se muestra el formulario de login' }
    $errores = Get-Content $consola | Select-String 'Uncaught|SyntaxError|Refused to|Failed to load|net::ERR'
    foreach ($linea in $errores) { $problemas += "Consola: $($linea.Line.Trim())" }

    if ($problemas.Count -gt 0) {
        $problemas | ForEach-Object { Write-Host "  - $_" }
        throw 'probar_interfaz: hay problemas'
    }
    Write-Host 'probar_interfaz: la página carga, muestra el login y la consola no tiene errores'
}
finally {
    Stop-Process -Id $servidor.Id -Force -ErrorAction SilentlyContinue
    Remove-Item $dom, $consola -ErrorAction SilentlyContinue
    Remove-Item $perfil -Recurse -Force -ErrorAction SilentlyContinue
}
