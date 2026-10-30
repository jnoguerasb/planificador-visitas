<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ApiTest;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * POST /api/visitas/validar y POST /api/visitas. Cada test usa su propia fecha para no
 * depender de lo que guardan los demás (el servidor confirma sus escrituras).
 */
final class ProgramarApiTest extends ApiTest
{
    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->entrar('planificador@test.local');
    }

    // --- Programar ---

    #[TestDox('POST /api/visitas: 201 con el id; la visita queda guardada con su origen calculado')]
    public function testProgramarCorrecto(): void
    {
        $respuesta = $this->postConToken('/api/visitas', $this->datos('2030-04-01', '12:00'), $this->token);

        self::assertSame(201, $respuesta['estado'], $respuesta['cuerpo']);
        $fila = $this->fila($respuesta['json']['id']);
        self::assertSame(['2030-04-01', '12:00', 'SEDE', 'Programada', 1], [
            $fila['fecha'], $fila['inicio'], $fila['origen_tipo'], $fila['estado'], $fila['creada_por'],
        ]);
    }

    #[TestDox('POST /api/visitas con desplazamiento 20: 422 "El desplazamiento mínimo es 30 min" y no guarda nada')]
    public function testDesplazamiento20(): void
    {
        $antes = $this->numeroDeVisitas();

        $respuesta = $this->postConToken('/api/visitas', $this->datos('2030-04-02', '12:00', ['desplazamiento_min' => 20]), $this->token);

        self::assertSame(422, $respuesta['estado']);
        self::assertSame(['errores' => [
            ['regla' => 'CAMPOS', 'mensaje' => 'El desplazamiento mínimo es 30 min', 'campo' => 'desplazamiento_min'],
        ]], $respuesta['json']);
        self::assertSame($antes, $this->numeroDeVisitas());
    }

    #[TestDox('Caso 20: el origen que envía el navegador se ignora y se guarda la visita anterior como origen')]
    public function testCaso20OrigenDelCliente(): void
    {
        $anterior = $this->insertarVisita(['fecha' => '2030-04-03']);

        $respuesta = $this->postConToken('/api/visitas', $this->datos('2030-04-03', '12:00', ['origen_tipo' => 'SEDE', 'origen_visita_id' => null]), $this->token);

        self::assertSame(201, $respuesta['estado'], $respuesta['cuerpo']);
        $fila = $this->fila($respuesta['json']['id']);
        self::assertSame(['VISITA', $anterior], [$fila['origen_tipo'], $fila['origen_visita_id']]);
    }

    #[TestDox('Caso 29: una visita que solapa con una Completada da 422 SOLAPE')]
    public function testCaso29SolapeConCompletada(): void
    {
        $this->insertarVisita(['fecha' => '2030-04-04', 'estado' => 'Completada', 'comentario_cierre' => 'Revisada sin incidencias']);

        $respuesta = $this->postConToken('/api/visitas', $this->datos('2030-04-04', '10:30'), $this->token);

        self::assertSame(422, $respuesta['estado']);
        self::assertSame([['regla' => 'SOLAPE', 'mensaje' => 'Solapa con la visita de 10:00 a 11:00 en Escuela Can Puiggener']], $respuesta['json']['errores']);
    }

    #[TestDox('Caso 18 por la API: la visita intercalada actualiza la siguiente')]
    public function testCaso18PorLaApi(): void
    {
        $existente = $this->insertarVisita(['fecha' => '2030-04-05']);

        $respuesta = $this->postConToken('/api/visitas', $this->datos('2030-04-05', '08:30', ['duracion_min' => 30, 'desplazamiento_siguiente_min' => 50]), $this->token);

        self::assertSame(201, $respuesta['estado'], $respuesta['cuerpo']);
        $siguiente = $this->fila($existente);
        self::assertSame(['VISITA', $respuesta['json']['id'], 50], [$siguiente['origen_tipo'], $siguiente['origen_visita_id'], $siguiente['desplazamiento_min']]);
    }

    #[TestDox('Caso 27: Ana llama a POST /api/visitas y recibe 403 PERMISO')]
    public function testCaso27UnTecnicoNoPuedeProgramar(): void
    {
        $this->cookies = [];
        $token = $this->entrar('ana@test.local');

        $respuesta = $this->postConToken('/api/visitas', $this->datos('2030-04-06', '12:00'), $token);

        self::assertSame(403, $respuesta['estado']);
        self::assertSame('PERMISO', $respuesta['json']['errores'][0]['regla']);
    }

    #[TestDox('Caso 30: POST /api/visitas sin token CSRF: 403 y no guarda nada')]
    public function testCaso30SinTokenCsrf(): void
    {
        $antes = $this->numeroDeVisitas();

        self::assertSame(403, $this->postJson('/api/visitas', $this->datos('2030-04-07', '12:00'))['estado']);
        self::assertSame($antes, $this->numeroDeVisitas());
    }

    #[TestDox('POST /api/visitas sin sesión: 401; con campos no esperados: 422; con JSON roto: 400')]
    public function testProgramarProtegidoYValidado(): void
    {
        $extra = $this->postConToken('/api/visitas', $this->datos('2030-04-08', '12:00', ['estado' => 'Completada']), $this->token);
        self::assertSame(422, $extra['estado']);
        self::assertSame([['regla' => 'CAMPOS', 'mensaje' => 'Campo no permitido: estado', 'campo' => 'estado']], $extra['json']['errores']);

        self::assertSame(400, $this->peticion('POST', '/api/visitas', '{"tecnico_id":', ['Content-Type' => 'application/json', 'X-CSRF-Token' => $this->token])['estado']);

        $this->cookies = [];
        self::assertSame(401, $this->postJson('/api/visitas', $this->datos('2030-04-08', '12:00'))['estado']);
    }

    // --- Validar ---

    #[TestDox('POST /api/visitas/validar: válida, con el origen calculado y la siguiente, sin guardar nada')]
    public function testValidarCorrecta(): void
    {
        $anterior = $this->insertarVisita(['fecha' => '2030-04-09']);
        $siguiente = $this->insertarVisita(['fecha' => '2030-04-09', 'hora_inicio' => '15:00', 'obra_id' => 3, 'desplazamiento_min' => 45,
            'origen_tipo' => 'VISITA', 'origen_visita_id' => $anterior]);
        $antes = $this->numeroDeVisitas();

        $respuesta = $this->postConToken('/api/visitas/validar', $this->datos('2030-04-09', '12:00'), $this->token);

        self::assertSame(200, $respuesta['estado'], $respuesta['cuerpo']);
        self::assertSame([
            'valida' => true,
            'origen' => ['tipo' => 'VISITA', 'visita_id' => $anterior, 'texto' => 'Desde Escuela Can Puiggener (acaba 11:00)'],
            'siguiente' => ['id' => $siguiente, 'obra' => 'Nave logística Can Roqueta', 'inicio' => '15:00', 'desplazamiento_min' => 45, 'pedir_desplazamiento' => true],
            'errores' => [],
        ], $respuesta['json']);
        self::assertSame($antes, $this->numeroDeVisitas());
    }

    #[TestDox('POST /api/visitas/validar: misma obra que la siguiente, no se pide el desplazamiento hasta ella')]
    public function testValidarMismaObraQueLaSiguiente(): void
    {
        $this->insertarVisita(['fecha' => '2030-04-10', 'hora_inicio' => '15:00', 'obra_id' => 2]);

        $respuesta = $this->postConToken('/api/visitas/validar', $this->datos('2030-04-10', '12:00'), $this->token);

        self::assertFalse($respuesta['json']['siguiente']['pedir_desplazamiento']);
    }

    #[TestDox('POST /api/visitas/validar: con errores responde 200, devuelve los errores y el origen, y no guarda nada')]
    public function testValidarConErrores(): void
    {
        $this->insertarVisita(['fecha' => '2030-04-11']);
        $antes = $this->numeroDeVisitas();

        $respuesta = $this->postConToken('/api/visitas/validar', $this->datos('2030-04-11', '11:00', ['desplazamiento_min' => 20]), $this->token);

        self::assertSame(200, $respuesta['estado']);
        self::assertFalse($respuesta['json']['valida']);
        self::assertSame('VISITA', $respuesta['json']['origen']['tipo']);
        self::assertSame([['regla' => 'CAMPOS', 'mensaje' => 'El desplazamiento mínimo es 30 min', 'campo' => 'desplazamiento_min']], $respuesta['json']['errores']);
        self::assertSame($antes, $this->numeroDeVisitas());
    }

    #[TestDox('La previsualización coincide con lo que dice Guardar')]
    public function testValidarYProgramarDicenLoMismo(): void
    {
        $this->insertarVisita(['fecha' => '2030-04-12']);
        $datos = $this->datos('2030-04-12', '10:30', ['duracion_min' => 420]);

        $validar = $this->postConToken('/api/visitas/validar', $datos, $this->token);
        $programar = $this->postConToken('/api/visitas', $datos, $this->token);

        self::assertSame(422, $programar['estado']);
        self::assertSame($programar['json']['errores'], $validar['json']['errores']);
        self::assertSame(['JORNADA', 'SOLAPE'], array_column($validar['json']['errores'], 'regla'));
    }

    #[TestDox('POST /api/visitas/validar: técnico 403, sin token 403, sin sesión 401, campos de más 422')]
    public function testValidarProtegido(): void
    {
        self::assertSame(403, $this->postJson('/api/visitas/validar', $this->datos('2030-04-13', '12:00'))['estado']);
        self::assertSame(422, $this->postConToken('/api/visitas/validar', $this->datos('2030-04-13', '12:00', ['x' => 1]), $this->token)['estado']);

        $this->cookies = [];
        self::assertSame(401, $this->postJson('/api/visitas/validar', $this->datos('2030-04-13', '12:00'))['estado']);

        $token = $this->entrar('ana@test.local');
        self::assertSame(403, $this->postConToken('/api/visitas/validar', $this->datos('2030-04-13', '12:00'), $token)['estado']);
    }

    /**
     * @param array<string, mixed> $cambios
     * @return array<string, mixed>
     */
    private function datos(string $fecha, string $hora, array $cambios = []): array
    {
        return array_merge([
            'tecnico_id' => 1,
            'obra_id' => 2,
            'fecha' => $fecha,
            'hora_inicio' => $hora,
            'duracion_min' => 60,
            'desplazamiento_min' => 30,
        ], $cambios);
    }
}
