<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Database;
use App\Tests\Support\ServicioTest;
use PHPUnit\Framework\Attributes\TestDox;
use RuntimeException;

/**
 * Dos planificadores programan a la vez visitas incompatibles para el mismo técnico: solo se guarda una.
 * Cada programación va en su propio proceso PHP con su propia conexión, como en el servidor web.
 */
final class ConcurrenciaTest extends ServicioTest
{
    private const ESPERA_MAXIMA_SEGUNDOS = 15;

    #[TestDox('Mientras otra transacción tiene bloqueado al técnico, la segunda espera y al continuar ve la visita nueva: SOLAPE')]
    public function testLaSegundaEsperaAlBloqueoYDaSolape(): void
    {
        // Primera transacción: bloquea a Ana e inserta su visita de 10:00 a 11:00, sin confirmar todavía.
        $primera = Database::conectar(self::urlDeEntorno('DATABASE_URL_TEST'));
        $primera->beginTransaction();
        $primera->prepare('SELECT id FROM tecnicos WHERE id = ? FOR UPDATE')->execute([1]);
        $primera->prepare(<<<'SQL'
            INSERT INTO visitas (tecnico_id, obra_id, fecha, hora_inicio, duracion_min, desplazamiento_min, origen_tipo, creada_por)
            VALUES (1, 1, ?, '10:00', 60, 30, 'SEDE', 1)
            SQL)->execute([self::FECHA]);

        // Segunda: el servicio real, en otro proceso, intenta 10:30–11:30 en otra obra.
        $segunda = $this->lanzar($this->datos('10:30', 60));
        $this->esperarAQueHayaUnaSesionBloqueada();

        $primera->commit();
        $resultado = $this->resultado($segunda);

        self::assertSame(['errores' => ['SOLAPE']], $resultado);
        self::assertSame(1, $this->visitasDeAnaEnFecha());
    }

    #[TestDox('Dos procesos programan a la vez visitas que se solapan: una se guarda y la otra da SOLAPE')]
    public function testDosAlMismoTiempoSoloSeGuardaUna(): void
    {
        $a = $this->lanzar($this->datos('10:00', 60, ['obra_id' => 1]));
        $b = $this->lanzar($this->datos('10:30', 60, ['obra_id' => 2]));

        $resultados = [$this->resultado($a), $this->resultado($b)];

        $guardadas = array_filter($resultados, fn (array $r) => isset($r['id']));
        $rechazadas = array_filter($resultados, fn (array $r) => isset($r['errores']));
        self::assertCount(1, $guardadas, json_encode($resultados));
        self::assertSame([['errores' => ['SOLAPE']]], array_values($rechazadas));
        self::assertSame(1, $this->visitasDeAnaEnFecha());
    }

    /**
     * @param array<string, mixed> $datos
     * @return array{proceso: resource, salida: resource, errores: resource}
     */
    private function lanzar(array $datos): array
    {
        $entorno = getenv();
        $entorno['DATABASE_URL'] = self::urlDeEntorno('DATABASE_URL_TEST');

        $proceso = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/Support/programar_visita.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $tuberias,
            null,
            $entorno
        );
        if ($proceso === false) {
            throw new RuntimeException('No se ha podido lanzar el segundo proceso');
        }
        fwrite($tuberias[0], json_encode($datos, JSON_THROW_ON_ERROR));
        fclose($tuberias[0]);

        return ['proceso' => $proceso, 'salida' => $tuberias[1], 'errores' => $tuberias[2]];
    }

    /**
     * @param array{proceso: resource, salida: resource, errores: resource} $lanzado
     * @return array<string, mixed>
     */
    private function resultado(array $lanzado): array
    {
        $salida = (string) stream_get_contents($lanzado['salida']);
        $errores = (string) stream_get_contents($lanzado['errores']);
        fclose($lanzado['salida']);
        fclose($lanzado['errores']);
        $codigo = proc_close($lanzado['proceso']);

        self::assertSame(0, $codigo, "El proceso ha fallado: {$errores}");

        return json_decode($salida, true, 16, JSON_THROW_ON_ERROR);
    }

    /** Comprueba en PostgreSQL que la segunda sesión está esperando un bloqueo, no solo que tarda. */
    private function esperarAQueHayaUnaSesionBloqueada(): void
    {
        $limite = microtime(true) + self::ESPERA_MAXIMA_SEGUNDOS;
        do {
            $esperando = (int) $this->app()->query(
                "SELECT COUNT(*) FROM pg_stat_activity WHERE datname = current_database() AND wait_event_type = 'Lock'"
            )->fetchColumn();
            if ($esperando === 1) {
                return;
            }
            usleep(100_000);
        } while (microtime(true) < $limite);

        self::fail('La segunda transacción no ha llegado a esperar el bloqueo del técnico');
    }

    private function visitasDeAnaEnFecha(): int
    {
        return (int) $this->valor('SELECT COUNT(*) FROM visitas WHERE tecnico_id = 1 AND fecha = ?', [self::FECHA]);
    }
}
