<?php

declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;

/**
 * Crea conexiones PDO a PostgreSQL a partir de una URL del tipo
 * postgres://usuario:clave@host:5432/base?sslmode=require
 *
 * Los mensajes de error nunca incluyen la URL, porque contiene la contraseña.
 */
final class Database
{
    private const SSLMODES_PERMITIDOS = ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'];

    public static function desdeEntorno(string $variable = 'DATABASE_URL'): PDO
    {
        $url = getenv($variable);
        if ($url === false || $url === '') {
            throw new RuntimeException("Falta la variable de entorno {$variable}");
        }

        return self::conectar($url);
    }

    public static function conectar(string $url): PDO
    {
        $partes = parse_url($url);
        if (
            $partes === false
            || !in_array($partes['scheme'] ?? '', ['postgres', 'postgresql'], true)
            || !isset($partes['host'], $partes['user'], $partes['path'])
            || trim($partes['path'], '/') === ''
        ) {
            throw new RuntimeException('La URL de base de datos no tiene el formato postgres://usuario:clave@host:puerto/base');
        }

        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            $partes['host'],
            $partes['port'] ?? 5432,
            rawurldecode(trim($partes['path'], '/'))
        );

        parse_str($partes['query'] ?? '', $parametros);
        if (isset($parametros['sslmode'])) {
            if (!in_array($parametros['sslmode'], self::SSLMODES_PERMITIDOS, true)) {
                throw new RuntimeException('Valor de sslmode no permitido en la URL de base de datos');
            }
            $dsn .= ';sslmode=' . $parametros['sslmode'];
        }

        $pdo = new PDO(
            $dsn,
            rawurldecode($partes['user']),
            rawurldecode($partes['pass'] ?? ''),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );

        // CURRENT_DATE y CURRENT_TIMESTAMP en hora de Madrid, como el resto de la aplicación (Reloj).
        $pdo->prepare("SELECT set_config('TimeZone', ?, false)")->execute([Reloj::ZONA_HORARIA]);

        return $pdo;
    }
}
