<?php

declare(strict_types=1);

// Ejecuta "php -l" sobre todos los .php de src/, public/, bin/ y tests/. Sale con 1 si alguno falla.

$raiz = dirname(__DIR__);
$total = 0;
$errores = 0;

foreach (['src', 'public', 'bin', 'tests'] as $carpeta) {
    $ruta = $raiz . DIRECTORY_SEPARATOR . $carpeta;
    if (!is_dir($ruta)) {
        continue;
    }

    $archivos = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($ruta, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($archivos as $archivo) {
        if ($archivo->getExtension() !== 'php') {
            continue;
        }
        $total++;

        // Comando como array: no pasa por la shell.
        $proceso = proc_open(
            [PHP_BINARY, '-l', $archivo->getPathname()],
            [1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $tuberias
        );
        $salida = stream_get_contents($tuberias[1]);
        fclose($tuberias[1]);

        if (proc_close($proceso) !== 0) {
            $errores++;
            echo $salida;
        }
    }
}

echo "lint: {$total} archivos revisados, {$errores} con errores" . PHP_EOL;
exit($errores === 0 ? 0 : 1);
