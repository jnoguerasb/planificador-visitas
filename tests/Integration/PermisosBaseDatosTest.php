<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Database;
use App\Tests\Support\BaseDatosTest;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * El usuario de la aplicación (app_planificador) solo puede leer, insertar y actualizar.
 */
final class PermisosBaseDatosTest extends BaseDatosTest
{
    private ?PDO $app = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = Database::conectar(self::urlDeEntorno('DATABASE_URL_TEST'));
        $this->app->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->app !== null && $this->app->inTransaction()) {
            $this->app->rollBack();
        }
        $this->app = null;
        parent::tearDown();
    }

    private function app(): PDO
    {
        self::assertNotNull($this->app);

        return $this->app;
    }

    public function testLaConexionEsConElUsuarioDeLaAplicacion(): void
    {
        self::assertSame('app_planificador', $this->app()->query('SELECT current_user')->fetchColumn());
    }

    public function testPuedeLeerInsertarYActualizarVisitas(): void
    {
        self::assertSame(12, $this->app()->query('SELECT COUNT(*) FROM visitas')->fetchColumn());

        $id = $this->app()->query(
            "INSERT INTO visitas (tecnico_id, obra_id, fecha, hora_inicio, duracion_min, origen_tipo)
             VALUES (4, 1, CURRENT_DATE + 30, '10:00', 60, 'SEDE') RETURNING id"
        )->fetchColumn();
        self::assertIsInt($id);

        $actualizar = $this->app()->prepare('UPDATE visitas SET revisar_desplazamiento = TRUE WHERE id = :id');
        $actualizar->execute(['id' => $id]);
        self::assertSame(1, $actualizar->rowCount());
    }

    /** @return array<string, array{string}> */
    public static function operacionesProhibidas(): array
    {
        return [
            'DELETE sobre visitas' => ['DELETE FROM visitas WHERE id = 12'],
            'DELETE sobre usuarios' => ['DELETE FROM usuarios'],
            'TRUNCATE de visitas' => ['TRUNCATE visitas'],
            'DROP TABLE' => ['DROP TABLE visitas'],
            'CREATE TABLE' => ['CREATE TABLE intrusa (id INT)'],
            'ALTER TABLE' => ['ALTER TABLE visitas DROP CONSTRAINT ck_visitas_duracion'],
        ];
    }

    #[DataProvider('operacionesProhibidas')]
    public function testLaOperacionFallaPorPermisos(string $sql): void
    {
        try {
            $this->app()->exec($sql);
            self::fail('Se esperaba un error de permisos');
        } catch (PDOException $e) {
            // 42501 = insufficient_privilege
            self::assertSame('42501', $e->getCode(), $e->getMessage());
        }

        self::assertSame(12, $this->valor('SELECT COUNT(*) FROM visitas'));
    }
}
