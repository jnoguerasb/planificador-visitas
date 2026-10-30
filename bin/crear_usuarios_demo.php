<?php

declare(strict_types=1);

// Crea los usuarios de demostración con la contraseña de DEMO_PASSWORD:
//   planificador@demo.local (PLANIFICADOR) y un usuario por técnico activo (ana@demo.local, ...).
// Se puede ejecutar varias veces: no duplica usuarios y solo cambia el hash si DEMO_PASSWORD ha cambiado.
// Uso: php bin/crear_usuarios_demo.php   (con DATABASE_URL y DEMO_PASSWORD en el entorno)

use App\Database;

require dirname(__DIR__) . '/vendor/autoload.php';

const DOMINIO_DEMO = 'demo.local';

/** "Ana Puig" -> "ana@demo.local". Solo letras a-z en la parte local. */
function emailDeTecnico(string $nombre): string
{
    $primerNombre = mb_strtolower(explode(' ', trim($nombre))[0]);
    $sinAcentos = strtr($primerNombre, [
        'á' => 'a', 'à' => 'a', 'é' => 'e', 'è' => 'e', 'í' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ú' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n',
    ]);
    if (preg_match('/^[a-z]+$/', $sinAcentos) !== 1) {
        throw new RuntimeException('No se puede generar un email de demo para uno de los técnicos');
    }

    return $sinAcentos . '@' . DOMINIO_DEMO;
}

$clave = getenv('DEMO_PASSWORD');
if ($clave === false || $clave === '') {
    fwrite(STDERR, "Error: falta la variable de entorno DEMO_PASSWORD. Defínela en .env y carga el entorno.\n");
    exit(1);
}

try {
    $pdo = Database::desdeEntorno('DATABASE_URL');

    $usuarios = [['email' => 'planificador@' . DOMINIO_DEMO, 'rol' => 'PLANIFICADOR', 'tecnico_id' => null]];
    $tecnicos = $pdo->query('SELECT id, nombre FROM tecnicos WHERE activo ORDER BY id')->fetchAll();
    foreach ($tecnicos as $tecnico) {
        $usuarios[] = ['email' => emailDeTecnico($tecnico['nombre']), 'rol' => 'TECNICO', 'tecnico_id' => $tecnico['id']];
    }

    $buscar = $pdo->prepare('SELECT id, password_hash FROM usuarios WHERE email = :email');
    $insertar = $pdo->prepare(
        'INSERT INTO usuarios (email, password_hash, rol, tecnico_id) VALUES (:email, :hash, :rol, :tecnico_id)'
    );
    $actualizar = $pdo->prepare('UPDATE usuarios SET password_hash = :hash WHERE id = :id');

    $creados = 0;
    $actualizados = 0;
    $sinCambios = 0;

    $pdo->beginTransaction();
    foreach ($usuarios as $usuario) {
        $buscar->execute(['email' => $usuario['email']]);
        $existente = $buscar->fetch();

        if ($existente === false) {
            $insertar->execute([
                'email' => $usuario['email'],
                'hash' => password_hash($clave, PASSWORD_DEFAULT),
                'rol' => $usuario['rol'],
                'tecnico_id' => $usuario['tecnico_id'],
            ]);
            $creados++;
        } elseif (!password_verify($clave, $existente['password_hash'])) {
            $actualizar->execute(['hash' => password_hash($clave, PASSWORD_DEFAULT), 'id' => $existente['id']]);
            $actualizados++;
        } else {
            $sinCambios++;
        }
    }
    $pdo->commit();

    echo "Usuarios demo: {$creados} creados, {$actualizados} con contraseña actualizada, {$sinCambios} sin cambios.\n";
    foreach ($usuarios as $usuario) {
        echo "  {$usuario['email']} ({$usuario['rol']})\n";
    }
} catch (Throwable $e) {
    // Detalle solo en el log de errores; por pantalla, un mensaje sin datos de conexión.
    error_log('crear_usuarios_demo: ' . $e->getMessage());
    fwrite(STDERR, "Error: no se han podido crear los usuarios demo. Revisa DATABASE_URL y que la base esté cargada.\n");
    exit(1);
}
