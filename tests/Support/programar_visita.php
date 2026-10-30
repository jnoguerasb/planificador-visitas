<?php

declare(strict_types=1);

// Ayuda para ConcurrenciaTest: programa una visita como planificador (usuario 1) en un proceso aparte.
// Lee los datos en JSON por la entrada estándar y escribe el resultado en JSON: {"id": n} o {"errores": [...]}.
// La conexión sale de DATABASE_URL, que el test apunta a la base de tests.

use App\Database;
use App\ErroresDeNegocio;
use App\Reloj;
use App\Usuario;
use App\VisitaService;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$datos = json_decode((string) stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
$servicio = new VisitaService(Database::desdeEntorno('DATABASE_URL'), new Reloj());

try {
    echo json_encode(['id' => $servicio->programar(new Usuario(1, Usuario::PLANIFICADOR, null), $datos)]);
} catch (ErroresDeNegocio $e) {
    echo json_encode(['errores' => $e->reglas()]);
}
