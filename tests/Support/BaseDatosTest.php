<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Database;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Clase base de los tests contra planificador_test.
 *
 * Antes de cada clase de test borra el esquema y vuelve a cargar 001_esquema.sql y seed.sql
 * con el usuario propietario (DATABASE_ADMIN_URL_TEST). Cada test se ejecuta dentro de una
 * transacción que se deshace al acabar, para que no afecte a los demás.
 */
abstract class BaseDatosTest extends TestCase
{
    protected static ?PDO $admin = null;

    protected static function urlDeEntorno(string $variable): string
    {
        $url = getenv($variable);
        if ($url === false || $url === '') {
            throw new RuntimeException("Falta la variable de entorno {$variable}. Carga el entorno con . .\\scripts\\entorno.ps1");
        }
        // Protección: los tests nunca deben tocar la base de desarrollo.
        $base = trim((string) parse_url($url, PHP_URL_PATH), '/');
        if (!str_ends_with($base, '_test')) {
            throw new RuntimeException("{$variable} debe apuntar a una base cuyo nombre acabe en _test");
        }

        return $url;
    }

    public static function setUpBeforeClass(): void
    {
        self::$admin = Database::conectar(self::urlDeEntorno('DATABASE_ADMIN_URL_TEST'));
        self::recrearEsquema(self::$admin);
    }

    public static function tearDownAfterClass(): void
    {
        self::$admin = null;
    }

    protected static function recrearEsquema(PDO $pdo): void
    {
        $raiz = dirname(__DIR__, 2);
        $pdo->exec('DROP SCHEMA IF EXISTS public CASCADE');
        $pdo->exec('CREATE SCHEMA public');
        // Todas las migraciones, en orden (001, 002...), como recrear_bd.ps1.
        $migraciones = glob($raiz . '/db/migrations/*.sql') ?: [];
        sort($migraciones);
        foreach ($migraciones as $migracion) {
            $pdo->exec((string) file_get_contents($migracion));
        }
        $pdo->exec((string) file_get_contents($raiz . '/db/seed.sql'));
    }

    protected function admin(): PDO
    {
        if (self::$admin === null) {
            throw new RuntimeException('La conexión de administración no está abierta');
        }

        return self::$admin;
    }

    protected function setUp(): void
    {
        $this->admin()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->admin()->inTransaction()) {
            $this->admin()->rollBack();
        }
    }

    /** Ejecuta una consulta preparada con la conexión de administración y devuelve un único valor. */
    protected function valor(string $sql, array $parametros = []): mixed
    {
        $sentencia = $this->admin()->prepare($sql);
        $sentencia->execute($parametros);

        return $sentencia->fetchColumn();
    }
}
