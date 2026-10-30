<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Database;
use App\Reloj;
use App\Usuario;
use App\VisitaService;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Clase base de los tests de servicios y repositorios.
 *
 * El servicio abre y confirma sus propias transacciones, así que aquí no sirve la transacción
 * que se deshace al acabar: antes de cada test se recrea el esquema con el seed y tres usuarios.
 * El servicio usa la conexión de la aplicación (DATABASE_URL_TEST), con sus permisos reales.
 *
 * Los tests trabajan en FECHA, lejos de las fechas del seed (relativas a hoy). Partida común
 * de los casos de prueba: Ana tiene una visita de 10:00 a 11:00 en la obra 1 (Escuela Can Puiggener).
 */
abstract class ServicioTest extends BaseDatosTest
{
    use DatosDePrueba;

    protected const FECHA = '2030-01-15';
    protected const OBRA_A = 'Escuela Can Puiggener';

    protected Usuario $planificador;
    protected Usuario $ana;
    protected Usuario $bernat;

    private ?PDO $app = null;

    protected function setUp(): void
    {
        self::recrearEsquema($this->admin());
        $this->admin()->exec(<<<'SQL'
            INSERT INTO usuarios (id, email, password_hash, rol, tecnico_id) VALUES
                (1, 'planificador@test.local', '-', 'PLANIFICADOR', NULL),
                (2, 'ana@test.local',          '-', 'TECNICO',      1),
                (3, 'bernat@test.local',       '-', 'TECNICO',      2);
            SELECT setval(pg_get_serial_sequence('usuarios', 'id'), 3);
            SQL);

        $this->planificador = new Usuario(1, Usuario::PLANIFICADOR, null);
        $this->ana = new Usuario(2, Usuario::TECNICO, 1);
        $this->bernat = new Usuario(3, Usuario::TECNICO, 2);
    }

    protected function tearDown(): void
    {
        $this->app = null;
    }

    /** Conexión con el usuario de la aplicación (app_planificador). */
    protected function app(): PDO
    {
        return $this->app ??= Database::conectar(self::urlDeEntorno('DATABASE_URL_TEST'));
    }

    /** Servicio con el reloj parado en $ahora (hora de Madrid). */
    protected function servicio(string $ahora = self::FECHA . ' 12:00'): VisitaService
    {
        return new VisitaService($this->app(), new Reloj(new DateTimeImmutable($ahora, new DateTimeZone('Europe/Madrid'))));
    }

    /**
     * Datos de una visita nueva como llegarían del formulario.
     *
     * @param array<string, mixed> $cambios
     * @return array<string, mixed>
     */
    protected function datos(string $hora, int $duracion, array $cambios = []): array
    {
        return array_merge([
            'tecnico_id' => 1,
            'obra_id' => 2,
            'fecha' => self::FECHA,
            'hora_inicio' => $hora,
            'duracion_min' => $duracion,
            'desplazamiento_min' => 30,
        ], $cambios);
    }
}
