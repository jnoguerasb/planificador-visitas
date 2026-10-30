<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ApiTest;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * POST /api/visitas/{id}/completar y /cancelar. El servidor usa el reloj real: las visitas que
 * ya se pueden completar son de ayer y las que aún no, de mañana.
 */
final class CierreApiTest extends ApiTest
{
    private const COMENTARIO = 'Revisada la estructura sin incidencias';

    // --- Completar ---

    #[TestDox('Caso 21: Ana completa su visita con comentario: 200 y se guardan comentario y autor')]
    public function testCaso21Completar(): void
    {
        $id = $this->visitaDeAyer();
        $token = $this->entrar('ana@test.local');

        $respuesta = $this->postConToken("/api/visitas/{$id}/completar", ['comentario' => self::COMENTARIO], $token);

        self::assertSame(200, $respuesta['estado'], $respuesta['cuerpo']);
        self::assertSame(['id' => $id, 'estado' => 'Completada'], $respuesta['json']);
        $fila = $this->fila($id);
        self::assertSame(['Completada', self::COMENTARIO, 2], [$fila['estado'], $fila['comentario_cierre'], $fila['completada_por']]);
        self::assertNotNull($fila['completada_en']);
    }

    #[TestDox('Caso 22: completar una visita de mañana: 422 MOMENTO')]
    public function testCaso22CompletarAntesDeTiempo(): void
    {
        $id = $this->insertarVisita(['fecha' => self::diaRelativo(1), 'hora_inicio' => '09:00']);
        $token = $this->entrar('ana@test.local');

        $respuesta = $this->postConToken("/api/visitas/{$id}/completar", ['comentario' => self::COMENTARIO], $token);

        self::assertSame(422, $respuesta['estado']);
        self::assertSame('MOMENTO', $respuesta['json']['errores'][0]['regla']);
        self::assertSame('Programada', $this->fila($id)['estado']);
    }

    #[TestDox('Caso 23: completar con un comentario de 4 caracteres: 422 TEXTO')]
    public function testCaso23ComentarioCorto(): void
    {
        $id = $this->visitaDeAyer();
        $token = $this->entrar('ana@test.local');

        $respuesta = $this->postConToken("/api/visitas/{$id}/completar", ['comentario' => 'Hola'], $token);

        self::assertSame(422, $respuesta['estado']);
        self::assertSame([['regla' => 'TEXTO', 'mensaje' => 'Describe el trabajo realizado (mínimo 10 caracteres)', 'campo' => 'comentario']], $respuesta['json']['errores']);
    }

    #[TestDox('Caso 25: Bernat completa o cancela una visita de Ana: 403 PERMISO')]
    public function testCaso25VisitaAjena(): void
    {
        $id = $this->visitaDeAyer();
        $token = $this->entrar('bernat@test.local');

        foreach (["/api/visitas/{$id}/completar" => ['comentario' => self::COMENTARIO], "/api/visitas/{$id}/cancelar" => ['motivo' => 'Obra cerrada']] as $ruta => $datos) {
            $respuesta = $this->postConToken($ruta, $datos, $token);

            self::assertSame(403, $respuesta['estado'], $ruta);
            self::assertSame('PERMISO', $respuesta['json']['errores'][0]['regla'], $ruta);
        }
        self::assertSame('Programada', $this->fila($id)['estado']);
    }

    #[TestDox('Caso 28: completar una Cancelada o cancelar una Completada: 409 ESTADO')]
    public function testCaso28TransicionesNoPermitidas(): void
    {
        $cancelada = $this->visitaDeAyer(['estado' => 'Cancelada', 'motivo_cancelacion' => 'Obra cerrada']);
        $completada = $this->visitaDeAyer(['hora_inicio' => '13:00', 'estado' => 'Completada', 'comentario_cierre' => self::COMENTARIO]);
        $token = $this->entrar('ana@test.local');

        $completar = $this->postConToken("/api/visitas/{$cancelada}/completar", ['comentario' => self::COMENTARIO], $token);
        $cancelar = $this->postConToken("/api/visitas/{$completada}/cancelar", ['motivo' => 'Obra cerrada'], $token);

        self::assertSame([409, 'ESTADO', 'La visita ya está Cancelada'], [$completar['estado'], $completar['json']['errores'][0]['regla'], $completar['json']['errores'][0]['mensaje']]);
        self::assertSame([409, 'ESTADO'], [$cancelar['estado'], $cancelar['json']['errores'][0]['regla']]);
    }

    #[TestDox('El planificador no puede completar: 403')]
    public function testElPlanificadorNoCompleta(): void
    {
        $id = $this->visitaDeAyer();
        $token = $this->entrar('planificador@test.local');

        self::assertSame(403, $this->postConToken("/api/visitas/{$id}/completar", ['comentario' => self::COMENTARIO], $token)['estado']);
    }

    // --- Cancelar ---

    #[TestDox('El planificador cancela con motivo: 200 y se guardan motivo y autor')]
    public function testElPlanificadorCancela(): void
    {
        $id = $this->visitaDeAyer();
        $token = $this->entrar('planificador@test.local');

        $respuesta = $this->postConToken("/api/visitas/{$id}/cancelar", ['motivo' => 'La obra está cerrada'], $token);

        self::assertSame(200, $respuesta['estado'], $respuesta['cuerpo']);
        self::assertSame(['id' => $id, 'estado' => 'Cancelada'], $respuesta['json']);
        $fila = $this->fila($id);
        self::assertSame(['Cancelada', 'La obra está cerrada', 1], [$fila['estado'], $fila['motivo_cancelacion'], $fila['cancelada_por']]);
    }

    #[TestDox('Ana cancela una visita suya futura: 200')]
    public function testElTecnicoCancelaLaSuya(): void
    {
        $id = $this->insertarVisita(['fecha' => self::diaRelativo(3), 'hora_inicio' => '09:00']);
        $token = $this->entrar('ana@test.local');

        self::assertSame(200, $this->postConToken("/api/visitas/{$id}/cancelar", ['motivo' => 'Estoy de baja'], $token)['estado']);
        self::assertSame(2, $this->fila($id)['cancelada_por']);
    }

    #[TestDox('Caso 24: cancelar sin motivo: 422 TEXTO')]
    public function testCaso24CancelarSinMotivo(): void
    {
        $id = $this->visitaDeAyer();
        $token = $this->entrar('ana@test.local');

        foreach ([[], ['motivo' => ''], ['motivo' => '   ']] as $datos) {
            $respuesta = $this->postConToken("/api/visitas/{$id}/cancelar", $datos, $token);

            self::assertSame(422, $respuesta['estado'], json_encode($datos));
            self::assertSame('TEXTO', $respuesta['json']['errores'][0]['regla']);
        }
        self::assertSame('Programada', $this->fila($id)['estado']);
    }

    // --- Comunes ---

    #[TestDox('Visita inexistente o id fuera de rango: 404; id que no es un número: 404 de ruta')]
    public function testVisitaInexistente(): void
    {
        $token = $this->entrar('ana@test.local');

        foreach (['999999', '99999999999', '99999999999999999999'] as $id) {
            $respuesta = $this->postConToken("/api/visitas/{$id}/cancelar", ['motivo' => 'Obra cerrada'], $token);

            self::assertSame(404, $respuesta['estado'], $id);
            self::assertSame('NO_ENCONTRADO', $respuesta['json']['errores'][0]['regla'], $id);
        }
        foreach (['abc', '0', '-1', '01'] as $id) {
            self::assertSame(404, $this->postConToken("/api/visitas/{$id}/cancelar", ['motivo' => 'Obra cerrada'], $token)['estado'], $id);
        }
    }

    #[TestDox('Completar y cancelar: sin sesión 401, sin token 403, campos de más 422')]
    public function testCierreProtegido(): void
    {
        $id = $this->visitaDeAyer();

        self::assertSame(401, $this->postJson("/api/visitas/{$id}/cancelar", ['motivo' => 'Obra cerrada'])['estado']);

        $token = $this->entrar('ana@test.local');
        self::assertSame(403, $this->postJson("/api/visitas/{$id}/cancelar", ['motivo' => 'Obra cerrada'])['estado']);
        self::assertSame(403, $this->postJson("/api/visitas/{$id}/completar", ['comentario' => self::COMENTARIO])['estado']);
        self::assertSame(422, $this->postConToken("/api/visitas/{$id}/cancelar", ['motivo' => 'Obra cerrada', 'cancelada_por' => 1], $token)['estado']);
        self::assertSame(422, $this->postConToken("/api/visitas/{$id}/completar", ['comentario' => self::COMENTARIO, 'estado' => 'Completada'], $token)['estado']);
        self::assertSame('Programada', $this->fila($id)['estado']);
    }

    /** @param array<string, mixed> $campos */
    private function visitaDeAyer(array $campos = []): int
    {
        return $this->insertarVisita($campos + ['fecha' => self::diaRelativo(-1), 'hora_inicio' => '09:00']);
    }
}
