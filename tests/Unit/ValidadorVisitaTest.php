<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\ErrorRegla;
use App\ResultadoValidacion;
use App\ValidadorVisita;
use App\Visita;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Casos de la sección 8 (1–20, salvo el 19, que está en ReasignadorOrigenTest) y ejemplos de la
 * sección 4 del análisis. Partida común: Ana tiene una visita Programada de 10:00 a 11:00 en la
 * Obra A, con 30 min de desplazamiento desde la sede.
 */
final class ValidadorVisitaTest extends TestCase
{
    private const FECHA = '2026-11-03';
    private const TECNICOS = [1 => 'Ana Puig', 2 => 'Bernat Soler'];
    private const OBRAS = [1 => 'Obra A', 2 => 'Obra B', 3 => 'Obra C'];

    // --- Horario básico (sección 8) ---

    #[TestDox('Caso 1: 12:00–13:00 en la Obra B con 30 min se guarda')]
    public function testCaso01CasoNormalSeGuarda(): void
    {
        $resultado = $this->validar($this->datos('12:00', 60));

        $this->assertSinErrores($resultado);
        self::assertSame(Visita::ORIGEN_VISITA, $resultado->origenTipo());
        self::assertSame(1, $resultado->anterior?->id);
        self::assertNull($resultado->siguiente);
    }

    #[TestDox('Caso 2: 10:30–11:30 en la Obra A da SOLAPE')]
    public function testCaso02SolapeParcial(): void
    {
        $resultado = $this->validar($this->datos('10:30', 60, ['obra_id' => 1]));

        $this->assertErrores([[ErrorRegla::SOLAPE, 'Solapa con la visita de 10:00 a 11:00 en Obra A']], $resultado);
    }

    #[TestDox('Caso 3: 09:00–12:00 da SOLAPE y no además DESPLAZAMIENTO')]
    public function testCaso03UnSoloErrorPorConflicto(): void
    {
        $resultado = $this->validar($this->datos('09:00', 180));

        $this->assertErrores([[ErrorRegla::SOLAPE, 'Solapa con la visita de 10:00 a 11:00 en Obra A']], $resultado);
    }

    #[TestDox('Caso 4: 11:00–12:00 en la misma Obra A se guarda sin desplazamiento (R1: los 30 min solo entre obras diferentes)')]
    public function testCaso04MismaObraSinDesplazamiento(): void
    {
        $resultado = $this->validar($this->datos('11:00', 60, ['obra_id' => 1, 'desplazamiento_min' => 0]));

        $this->assertSinErrores($resultado);
        self::assertSame(0, $resultado->desplazamientoMinimo);
        // Si se indica un desplazamiento, se respeta: con 30 min no da tiempo.
        $this->assertErrores([[
            ErrorRegla::DESPLAZAMIENTO,
            'No da tiempo a llegar desde Obra A (acaba 11:00): con 30 min de desplazamiento, como pronto a las 11:30',
        ]], $this->validar($this->datos('11:00', 60, ['obra_id' => 1])));
    }

    #[TestDox('Caso 5: 11:00–12:00 en la Obra B, pegada a la anterior, da DESPLAZAMIENTO')]
    public function testCaso05PegadasEnObrasDistintas(): void
    {
        $resultado = $this->validar($this->datos('11:00', 60));

        $this->assertErrores([[
            ErrorRegla::DESPLAZAMIENTO,
            'No da tiempo a llegar desde Obra A (acaba 11:00): con 30 min de desplazamiento, como pronto a las 11:30',
        ]], $resultado);
    }

    #[TestDox('Caso 6: 11:30–12:00 con exactamente 30 min se guarda')]
    public function testCaso06Exactamente30Min(): void
    {
        $this->assertSinErrores($this->validar($this->datos('11:30', 30)));
    }

    #[TestDox('Caso 7: 16:00–17:00 acaba justo a las 17:00 y se guarda')]
    public function testCaso07AcabaJustoALas17(): void
    {
        $this->assertSinErrores($this->validar($this->datos('16:00', 60)));
    }

    #[TestDox('Caso 8: 16:30–17:15 acaba después de las 17:00 y da JORNADA')]
    public function testCaso08AcabaDespuesDeLas17(): void
    {
        $resultado = $this->validar($this->datos('16:30', 45));

        $this->assertErrores([[ErrorRegla::JORNADA, 'La visita acabaría a las 17:15 y la jornada termina a las 17:00']], $resultado);
    }

    #[TestDox('Caso 9: 10:30–17:30 da SOLAPE y JORNADA a la vez')]
    public function testCaso09DevuelveTodosLosErrores(): void
    {
        $resultado = $this->validar($this->datos('10:30', 420));

        $this->assertErrores([
            [ErrorRegla::JORNADA, 'La visita acabaría a las 17:30 y la jornada termina a las 17:00'],
            [ErrorRegla::SOLAPE, 'Solapa con la visita de 10:00 a 11:00 en Obra A'],
        ], $resultado);
    }

    #[TestDox('Caso 10: 10:00–11:00 en la Obra A tras cancelar la existente se guarda')]
    public function testCaso10CancelarLiberaElHorario(): void
    {
        $resultado = $this->validar(
            $this->datos('10:00', 60, ['obra_id' => 1]),
            [$this->visitaA(Visita::CANCELADA)]
        );

        $this->assertSinErrores($resultado);
        self::assertSame(Visita::ORIGEN_SEDE, $resultado->origenTipo());
    }

    #[TestDox('Caso 11: el mismo horario para otro técnico se guarda')]
    public function testCaso11LasReglasSonPorTecnico(): void
    {
        $resultado = $this->validar($this->datos('10:00', 60, ['tecnico_id' => 2, 'obra_id' => 1]));

        $this->assertSinErrores($resultado);
        self::assertSame(Visita::ORIGEN_SEDE, $resultado->origenTipo());
    }

    #[TestDox('Caso 12: duración 0, técnico inexistente y fecha "31/02" dan CAMPOS')]
    public function testCaso12ValidacionDeEntrada(): void
    {
        $resultado = $this->validar($this->datos('12:00', 0, ['tecnico_id' => 99, 'fecha' => '31/02']));

        $this->assertErrores([
            [ErrorRegla::CAMPOS, 'Elige un técnico válido'],
            [ErrorRegla::CAMPOS, 'La fecha no es válida (formato AAAA-MM-DD)'],
            [ErrorRegla::CAMPOS, 'La duración debe estar entre 15 y 540 min'],
        ], $resultado);
        self::assertSame(['tecnico_id', 'fecha', 'duracion_min'], array_map(fn (ErrorRegla $e) => $e->campo, $resultado->errores));
        self::assertNull($resultado->visita);
    }

    // --- Desplazamiento (sección 8) ---

    #[TestDox('Caso 13: 11:30–12:30 con 45 min da DESPLAZAMIENTO (faltan 15 min)')]
    public function testCaso13ElValorIntroducidoMandaSiEsMayor(): void
    {
        $resultado = $this->validar($this->datos('11:30', 60, ['desplazamiento_min' => 45]));

        $this->assertErrores([[
            ErrorRegla::DESPLAZAMIENTO,
            'No da tiempo a llegar desde Obra A (acaba 11:00): con 45 min de desplazamiento, como pronto a las 11:45',
        ]], $resultado);
    }

    #[TestDox('Caso 14: desplazamiento 20 da CAMPOS "El desplazamiento mínimo es 30 min"')]
    public function testCaso14DesplazamientoMenorDe30SeRechaza(): void
    {
        $resultado = $this->validar($this->datos('11:30', 60, ['desplazamiento_min' => 20]));

        $this->assertErrores([[ErrorRegla::CAMPOS, 'El desplazamiento mínimo es 30 min']], $resultado);
        self::assertSame('desplazamiento_min', $resultado->errores[0]->campo);
        self::assertNull($resultado->visita, 'El servidor no corrige el valor: no hay visita validada');
    }

    #[TestDox('Caso 15: primera visita a las 08:15 con 30 min desde la sede da JORNADA')]
    public function testCaso15SalidaDeLaSedeAntesDeLas08(): void
    {
        $resultado = $this->validar($this->datos('08:15', 60), []);

        $this->assertErrores([[ErrorRegla::JORNADA, 'Con 30 min desde la sede, la visita no puede empezar antes de las 08:30']], $resultado);
    }

    #[TestDox('Caso 16: primera visita a las 08:30 con 30 min desde la sede se guarda con origen Sede')]
    public function testCaso16SalidaDeLaSedeALas08Justas(): void
    {
        $resultado = $this->validar($this->datos('08:30', 60), []);

        $this->assertSinErrores($resultado);
        self::assertSame(Visita::ORIGEN_SEDE, $resultado->origenTipo());
        self::assertNull($resultado->origenVisitaId());
    }

    #[TestDox('Caso 17: 08:30–09:15 con 50 min hasta la siguiente da DESPLAZAMIENTO')]
    public function testCaso17ComprobacionHaciaLaSiguiente(): void
    {
        $resultado = $this->validar($this->datos('08:30', 45, ['desplazamiento_siguiente_min' => 50]));

        $this->assertErrores([[
            ErrorRegla::DESPLAZAMIENTO,
            'No da tiempo a llegar a la siguiente visita en Obra A a las 10:00: esta visita debería acabar como tarde a las 09:10',
        ]], $resultado);
    }

    #[TestDox('Caso 18: 08:30–09:00 con 50 min hasta la siguiente se guarda y la de las 10:00 pasará a salir de ella con 50 min')]
    public function testCaso18ActualizacionDeLaSiguiente(): void
    {
        $resultado = $this->validar($this->datos('08:30', 30, ['desplazamiento_siguiente_min' => 50]));

        $this->assertSinErrores($resultado);
        self::assertSame(Visita::ORIGEN_SEDE, $resultado->origenTipo());
        self::assertSame(1, $resultado->siguiente?->id);
        self::assertSame(50, $resultado->desplazamientoSiguiente);
    }

    #[TestDox('Caso 20: el origen que envía el navegador se ignora y se usa la visita anterior')]
    public function testCaso20ElOrigenNoSeFiaDelCliente(): void
    {
        $resultado = $this->validar($this->datos('12:00', 60, ['origen_tipo' => 'SEDE', 'origen_visita_id' => null]));

        $this->assertSinErrores($resultado);
        self::assertSame(Visita::ORIGEN_VISITA, $resultado->origenTipo());
        self::assertSame(1, $resultado->origenVisitaId());
        self::assertSame(1, $resultado->visita?->origenVisitaId);
    }

    #[TestDox('Caso 29: una visita que solapa con una Completada da SOLAPE')]
    public function testCaso29LasCompletadasSiguenOcupando(): void
    {
        $resultado = $this->validar($this->datos('10:30', 60), [$this->visitaA(Visita::COMPLETADA)]);

        $this->assertErrores([[ErrorRegla::SOLAPE, 'Solapa con la visita de 10:00 a 11:00 en Obra A']], $resultado);
    }

    // --- Ejemplos de la sección 4 ---

    #[TestDox('Ejemplo 1: 11:15–12:15 en la Obra B da DESPLAZAMIENTO (faltan 15 min)')]
    public function testEjemplo01FaltanQuinceMinutos(): void
    {
        $resultado = $this->validar($this->datos('11:15', 60));

        $this->assertErrores([[
            ErrorRegla::DESPLAZAMIENTO,
            'No da tiempo a llegar desde Obra A (acaba 11:00): con 30 min de desplazamiento, como pronto a las 11:30',
        ]], $resultado);
    }

    #[TestDox('Ejemplo 2: 11:30–12:30 en la Obra B se guarda')]
    public function testEjemplo02LlegadaJusta(): void
    {
        $this->assertSinErrores($this->validar($this->datos('11:30', 60)));
    }

    #[TestDox('Ejemplo 3: 10:30–11:30 en la Obra B da SOLAPE')]
    public function testEjemplo03Solape(): void
    {
        $resultado = $this->validar($this->datos('10:30', 60));

        $this->assertErrores([[ErrorRegla::SOLAPE, 'Solapa con la visita de 10:00 a 11:00 en Obra A']], $resultado);
    }

    #[TestDox('Ejemplo 4: 08:30–09:00 en la Obra B se guarda (sale de la sede a las 08:00 y llega a tiempo a la de las 10:00)')]
    public function testEjemplo04AntesDeLaExistente(): void
    {
        $resultado = $this->validar($this->datos('08:30', 30));

        $this->assertSinErrores($resultado);
        self::assertSame(Visita::ORIGEN_SEDE, $resultado->origenTipo());
        self::assertSame(1, $resultado->siguiente?->id);
        self::assertSame(30, $resultado->desplazamientoSiguiente, 'Sin valor nuevo, la siguiente conserva el suyo');
    }

    #[TestDox('Ejemplo 5: 09:30–09:50 en la Obra B da DESPLAZAMIENTO hacia la Obra A')]
    public function testEjemplo05JustificaLaSalida(): void
    {
        $resultado = $this->validar($this->datos('09:30', 20));

        $this->assertErrores([[
            ErrorRegla::DESPLAZAMIENTO,
            'No da tiempo a llegar a la siguiente visita en Obra A a las 10:00: esta visita debería acabar como tarde a las 09:30',
        ]], $resultado);
    }

    #[TestDox('Ejemplo 6: 11:30–12:30 con 45 min da DESPLAZAMIENTO (faltan 15 min)')]
    public function testEjemplo06DesplazamientoMayor(): void
    {
        $resultado = $this->validar($this->datos('11:30', 60, ['desplazamiento_min' => 45]));

        self::assertSame([ErrorRegla::DESPLAZAMIENTO], $resultado->reglas());
    }

    #[TestDox('Ejemplo 7: desplazamiento 20 da CAMPOS y no evalúa las reglas')]
    public function testEjemplo07DesplazamientoMenorSeRechaza(): void
    {
        $resultado = $this->validar($this->datos('11:30', 60, ['desplazamiento_min' => 20]));

        $this->assertErrores([[ErrorRegla::CAMPOS, 'El desplazamiento mínimo es 30 min']], $resultado);
    }

    #[TestDox('Ejemplo 8: 08:00–08:45 sin desplazamiento desde la sede se guarda (R2); con 30 min indicados, saldría a las 07:30: JORNADA')]
    public function testEjemplo08PrimeraVisitaALas08(): void
    {
        $resultado = $this->validar($this->datos('08:00', 45, ['desplazamiento_min' => 0]));

        $this->assertSinErrores($resultado);
        self::assertSame(0, $resultado->desplazamientoMinimo);
        $this->assertErrores(
            [[ErrorRegla::JORNADA, 'Con 30 min desde la sede, la visita no puede empezar antes de las 08:30']],
            $this->validar($this->datos('08:00', 45))
        );
    }

    #[TestDox('Ejemplo 9: 11:00–12:00 en la misma Obra A, pegada a la anterior, se guarda (R1)')]
    public function testEjemplo09MismaObra(): void
    {
        $this->assertSinErrores($this->validar($this->datos('11:00', 60, ['obra_id' => 1, 'desplazamiento_min' => 0])));
    }

    // --- Desplazamiento mínimo según el origen (R1 y R2) ---

    #[TestDox('R2: desde la sede el mínimo es 0; vacío o negativo da CAMPOS')]
    public function testDesdeLaSedeElMinimoEs0(): void
    {
        $this->assertSinErrores($this->validar($this->datos('08:00', 60, ['desplazamiento_min' => 0]), []));

        $this->assertErrores(
            [[ErrorRegla::CAMPOS, 'El desplazamiento no puede ser negativo']],
            $this->validar($this->datos('09:00', 60, ['desplazamiento_min' => -1]), [])
        );
        $vacio = $this->validar($this->datos('09:00', 60, ['desplazamiento_min' => '']), []);
        $this->assertErrores([[ErrorRegla::CAMPOS, 'Indica el desplazamiento en minutos']], $vacio);
        self::assertSame(0, $vacio->desplazamientoMinimo, 'El mínimo se informa aunque haya errores, para la previsualización');
    }

    #[TestDox('Entre obras diferentes el mínimo sigue siendo 30 (regla b)')]
    public function testEntreObrasDiferentesElMinimoEs30(): void
    {
        $resultado = $this->validar($this->datos('12:00', 60, ['desplazamiento_min' => 0]));

        $this->assertErrores([[ErrorRegla::CAMPOS, 'El desplazamiento mínimo es 30 min']], $resultado);
        self::assertSame(30, $resultado->desplazamientoMinimo);
    }

    #[TestDox('R1 hacia la siguiente: en la misma obra se puede encadenar sin desplazamiento')]
    public function testSiguienteEnLaMismaObra(): void
    {
        $resultado = $this->validar($this->datos('09:00', 60, ['obra_id' => 1, 'desplazamiento_min' => 0]));

        $this->assertSinErrores($resultado);
        self::assertSame(1, $resultado->siguiente?->id);
        self::assertSame(0, $resultado->desplazamientoSiguiente);
        self::assertSame(0, $resultado->desplazamientoSiguienteSugerido);
    }

    #[TestDox('Hacia una siguiente en otra obra se exigen 30 min, aunque esa visita saliera antes de la sede con menos')]
    public function testSiguienteEnOtraObraExige30(): void
    {
        $desdeLaSede = new Visita(1, 1, 1, 'Obra A', self::FECHA, 600, 60, 0); // 10:00, salía de la sede con 0 min

        $resultado = $this->validar($this->datos('09:00', 45, ['desplazamiento_min' => 0]), [$desdeLaSede]);
        self::assertSame(30, $resultado->desplazamientoSiguienteSugerido);
        $this->assertErrores([[
            ErrorRegla::DESPLAZAMIENTO,
            'No da tiempo a llegar a la siguiente visita en Obra A a las 10:00: esta visita debería acabar como tarde a las 09:30',
        ]], $resultado);

        $conCero = $this->validar($this->datos('08:00', 30, ['desplazamiento_min' => 0, 'desplazamiento_siguiente_min' => 0]), [$desdeLaSede]);
        $this->assertErrores([[ErrorRegla::CAMPOS, 'El desplazamiento mínimo es 30 min']], $conCero);
        self::assertSame('desplazamiento_siguiente_min', $conCero->errores[0]->campo);
    }

    // --- Límites exactos ---

    #[TestDox('Límite: llegada con 30 min justos desde la visita anterior se guarda; un minuto menos, no')]
    public function testLimiteTreintaMinutosJustos(): void
    {
        $this->assertSinErrores($this->validar($this->datos('11:30', 60)));
        self::assertSame([ErrorRegla::DESPLAZAMIENTO], $this->validar($this->datos('11:29', 60))->reglas());
    }

    #[TestDox('Límite: acabar a las 17:00 justas se guarda; a las 17:01, no')]
    public function testLimiteFinALas17Justas(): void
    {
        $this->assertSinErrores($this->validar($this->datos('16:00', 60), []));
        $this->assertErrores(
            [[ErrorRegla::JORNADA, 'La visita acabaría a las 17:01 y la jornada termina a las 17:00']],
            $this->validar($this->datos('16:00', 61), [])
        );
    }

    #[TestDox('Límite: salir de la sede a las 08:00 justas se guarda; a las 07:59, no')]
    public function testLimiteSalidaDeLaSedeALas08Justas(): void
    {
        $this->assertSinErrores($this->validar($this->datos('09:00', 60, ['desplazamiento_min' => 60]), []));
        $this->assertErrores(
            [[ErrorRegla::JORNADA, 'Con 60 min desde la sede, la visita no puede empezar antes de las 09:00']],
            $this->validar($this->datos('08:59', 60, ['desplazamiento_min' => 60]), [])
        );
    }

    #[TestDox('Límite: salida hacia la siguiente con el tiempo justo se guarda; un minuto más tarde, no')]
    public function testLimiteSalidaHaciaLaSiguienteJusta(): void
    {
        $this->assertSinErrores($this->validar($this->datos('08:30', 60)));
        self::assertSame([ErrorRegla::DESPLAZAMIENTO], $this->validar($this->datos('08:31', 60))->reglas());
    }

    #[TestDox('Límite: visitas que se tocan no solapan (intervalos semiabiertos)')]
    public function testLimiteIntervalosSemiabiertos(): void
    {
        // Con 30 min de desplazamiento, 11:00 da DESPLAZAMIENTO pero no SOLAPE.
        self::assertSame([ErrorRegla::DESPLAZAMIENTO], $this->validar($this->datos('11:00', 60))->reglas());
        // 09:00–10:00 acaba justo cuando empieza la de las 10:00: solo falla la salida.
        self::assertSame([ErrorRegla::DESPLAZAMIENTO], $this->validar($this->datos('09:00', 60))->reglas());
    }

    #[TestDox('Límite: duración 15 y 540, desplazamiento 30 y 240 se aceptan')]
    public function testLimitesDeCamposAceptados(): void
    {
        $this->assertSinErrores($this->validar($this->datos('12:00', 15), []));
        $this->assertSinErrores($this->validar($this->datos('12:00', 60, ['desplazamiento_min' => 30]), []));
        $this->assertSinErrores($this->validar($this->datos('12:00', 60, ['desplazamiento_min' => 240]), []));
        // Sin desplazamiento desde la sede (R2), una visita de 540 min ocupa la jornada entera.
        $this->assertSinErrores($this->validar($this->datos('08:00', 540, ['desplazamiento_min' => 0]), []));
    }

    #[TestDox('Una visita que acabaría pasada la medianoche da JORNADA (sin dar la vuelta al reloj)')]
    public function testAcabariaDespuesDeMedianoche(): void
    {
        $resultado = $this->validar($this->datos('23:00', 120));

        $this->assertErrores([[ErrorRegla::JORNADA, 'La visita acabaría después de medianoche y la jornada termina a las 17:00']], $resultado);
    }

    #[TestDox('Empezar antes de las 08:00 da JORNADA con un único mensaje')]
    public function testEmpezarAntesDeLas08(): void
    {
        $resultado = $this->validar($this->datos('07:45', 60), []);

        $this->assertErrores([[ErrorRegla::JORNADA, 'La visita no puede empezar antes de las 08:00']], $resultado);
    }

    // --- Validación de campos ---

    /** @return array<string, array{array<string, mixed>, string, string}> */
    public static function camposNoValidos(): array
    {
        return [
            'técnico como texto' => [['tecnico_id' => '1'], 'tecnico_id', 'Elige un técnico válido'],
            'técnico ausente' => [['tecnico_id' => null], 'tecnico_id', 'Elige un técnico válido'],
            'obra inexistente' => [['obra_id' => 99], 'obra_id', 'Elige una obra válida'],
            'obra como decimal' => [['obra_id' => 2.0], 'obra_id', 'Elige una obra válida'],
            'fecha imposible' => [['fecha' => '2026-02-31'], 'fecha', 'La fecha no es válida (formato AAAA-MM-DD)'],
            'fecha con hora' => [['fecha' => '2026-11-03 10:00'], 'fecha', 'La fecha no es válida (formato AAAA-MM-DD)'],
            'fecha numérica' => [['fecha' => 20261103], 'fecha', 'La fecha no es válida (formato AAAA-MM-DD)'],
            'hora sin formato' => [['hora_inicio' => '9:00'], 'hora_inicio', 'La hora de inicio no es válida (formato HH:MM)'],
            'hora numérica' => [['hora_inicio' => 600], 'hora_inicio', 'La hora de inicio no es válida (formato HH:MM)'],
            'duración 14' => [['duracion_min' => 14], 'duracion_min', 'La duración debe estar entre 15 y 540 min'],
            'duración 541' => [['duracion_min' => 541], 'duracion_min', 'La duración debe estar entre 15 y 540 min'],
            'duración como texto' => [['duracion_min' => '60'], 'duracion_min', 'La duración debe estar entre 15 y 540 min'],
            'desplazamiento 29' => [['desplazamiento_min' => 29], 'desplazamiento_min', 'El desplazamiento mínimo es 30 min'],
            'desplazamiento vacío' => [['desplazamiento_min' => ''], 'desplazamiento_min', 'El desplazamiento mínimo es 30 min'],
            'desplazamiento nulo' => [['desplazamiento_min' => null], 'desplazamiento_min', 'El desplazamiento mínimo es 30 min'],
            'desplazamiento decimal' => [['desplazamiento_min' => 30.5], 'desplazamiento_min', 'El desplazamiento mínimo es 30 min'],
            'desplazamiento booleano' => [['desplazamiento_min' => true], 'desplazamiento_min', 'El desplazamiento mínimo es 30 min'],
            'desplazamiento 241' => [['desplazamiento_min' => 241], 'desplazamiento_min', 'El desplazamiento no puede superar 240 min'],
            'hasta la siguiente 29' => [['desplazamiento_siguiente_min' => 29], 'desplazamiento_siguiente_min', 'El desplazamiento mínimo es 30 min'],
            'hasta la siguiente vacío' => [['desplazamiento_siguiente_min' => ''], 'desplazamiento_siguiente_min', 'El desplazamiento mínimo es 30 min'],
            'hasta la siguiente 241' => [['desplazamiento_siguiente_min' => 241], 'desplazamiento_siguiente_min', 'El desplazamiento no puede superar 240 min'],
        ];
    }

    /** @param array<string, mixed> $cambios */
    #[DataProvider('camposNoValidos')]
    public function testCampoNoValidoDaCampos(array $cambios, string $campo, string $mensaje): void
    {
        $resultado = $this->validar($this->datos('12:00', 60, $cambios));

        $this->assertErrores([[ErrorRegla::CAMPOS, $mensaje]], $resultado);
        self::assertSame($campo, $resultado->errores[0]->campo);
    }

    public function testFaltanTodosLosCamposDaUnErrorPorCampo(): void
    {
        $resultado = $this->validar([]);

        self::assertSame(
            ['tecnico_id', 'obra_id', 'fecha', 'hora_inicio', 'duracion_min', 'desplazamiento_min'],
            array_map(fn (ErrorRegla $e) => $e->campo, $resultado->errores)
        );
        self::assertSame(array_fill(0, 6, ErrorRegla::CAMPOS), $resultado->reglas());
        self::assertNull($resultado->origenTipo(), 'Sin hora válida no se puede calcular el origen');
    }

    public function testConErroresDeCamposNoSeEvaluanLasReglas(): void
    {
        // Solaparía con la de las 10:00, pero la duración ya no es válida.
        $resultado = $this->validar($this->datos('10:30', 600));

        self::assertSame([ErrorRegla::CAMPOS], $resultado->reglas());
    }

    public function testConErroresDeCamposYHoraValidaElOrigenSeCalculaIgualmente(): void
    {
        // La previsualización puede mostrar el origen aunque el desplazamiento esté mal.
        $resultado = $this->validar($this->datos('12:00', 60, ['desplazamiento_min' => 20]));

        self::assertSame(Visita::ORIGEN_VISITA, $resultado->origenTipo());
        self::assertSame(1, $resultado->origenVisitaId());
    }

    // --- Reglas con varias visitas ---

    #[TestDox('El origen es la visita inmediatamente anterior y la salida se mide con la inmediatamente siguiente')]
    public function testUsaLaAnteriorYLaSiguienteInmediatas(): void
    {
        $visitas = [
            $this->visitaA(),
            new Visita(2, 1, 3, 'Obra C', self::FECHA, 900, 60, 30, Visita::PROGRAMADA, 1),
            new Visita(3, 1, 3, 'Obra C', self::FECHA, 510, 30, 30),
        ];

        $resultado = $this->validar($this->datos('12:00', 60), $visitas);

        $this->assertSinErrores($resultado);
        self::assertSame(1, $resultado->anterior?->id);
        self::assertSame(2, $resultado->siguiente?->id);
        self::assertSame(30, $resultado->desplazamientoSiguiente);
    }

    #[TestDox('Solape con una visita y falta de tiempo con otra distinta: se informan los dos')]
    public function testSolapeConUnaYDesplazamientoConOtra(): void
    {
        $visitas = [
            $this->visitaA(),
            new Visita(2, 1, 3, 'Obra C', self::FECHA, 735, 60, 30), // 12:15–13:15
        ];

        // 11:00–12:30: llega pegada a la Obra A (DESPLAZAMIENTO) y solapa con la Obra C (SOLAPE).
        $resultado = $this->validar($this->datos('11:00', 90), $visitas);

        $this->assertErrores([
            [ErrorRegla::SOLAPE, 'Solapa con la visita de 12:15 a 13:15 en Obra C'],
            [
                ErrorRegla::DESPLAZAMIENTO,
                'No da tiempo a llegar desde Obra A (acaba 11:00): con 30 min de desplazamiento, como pronto a las 11:30',
            ],
        ], $resultado);
    }

    #[TestDox('Falla la llegada y la salida a la vez: se informan las dos')]
    public function testFallanLlegadaYSalida(): void
    {
        $visitas = [
            $this->visitaA(),
            new Visita(2, 1, 3, 'Obra C', self::FECHA, 750, 60, 30), // 12:30–13:30
        ];

        $resultado = $this->validar($this->datos('11:15', 60), $visitas);

        self::assertSame([ErrorRegla::DESPLAZAMIENTO, ErrorRegla::DESPLAZAMIENTO], $resultado->reglas());
    }

    #[TestDox('Solapa con varias visitas: un error por cada una')]
    public function testSolapaConVarias(): void
    {
        $visitas = [
            $this->visitaA(),
            new Visita(2, 1, 3, 'Obra C', self::FECHA, 720, 60, 30), // 12:00–13:00
        ];

        $resultado = $this->validar($this->datos('09:00', 270), $visitas); // 09:00–13:30

        self::assertSame([ErrorRegla::SOLAPE, ErrorRegla::SOLAPE], $resultado->reglas());
    }

    #[TestDox('Las visitas de otra fecha no cuentan')]
    public function testIgnoraVisitasDeOtraFecha(): void
    {
        $otroDia = new Visita(1, 1, 1, 'Obra A', '2026-11-04', 600, 60, 30);

        $resultado = $this->validar($this->datos('10:00', 60), [$otroDia]);

        $this->assertSinErrores($resultado);
        self::assertSame(Visita::ORIGEN_SEDE, $resultado->origenTipo());
    }

    public function testLaVisitaValidadaConservaExactamenteLosValoresRecibidos(): void
    {
        $resultado = $this->validar($this->datos('12:00', 75, ['desplazamiento_min' => 35, 'obra_id' => 3]));

        $visita = $resultado->visita;
        self::assertNotNull($visita);
        self::assertNull($visita->id);
        self::assertSame([1, 3, 'Obra C', self::FECHA, 720, 75, 35, Visita::PROGRAMADA], [
            $visita->tecnicoId, $visita->obraId, $visita->obraNombre, $visita->fecha,
            $visita->inicio, $visita->duracion, $visita->desplazamiento, $visita->estado,
        ]);
        self::assertSame(795, $visita->fin());
    }

    public function testDesplazamientoHastaLaSiguienteSinVisitaSiguienteSeIgnora(): void
    {
        $resultado = $this->validar($this->datos('12:00', 60, ['desplazamiento_siguiente_min' => 60]));

        $this->assertSinErrores($resultado);
        self::assertNull($resultado->siguiente);
        self::assertNull($resultado->desplazamientoSiguiente);
    }

    // --- Ayudas ---

    /**
     * @param array<string, mixed> $cambios
     * @return array<string, mixed>
     */
    private function datos(string $hora, int $duracion, array $cambios = []): array
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

    private function visitaA(string $estado = Visita::PROGRAMADA): Visita
    {
        return new Visita(1, 1, 1, 'Obra A', self::FECHA, 600, 60, 30, $estado);
    }

    /**
     * @param array<string, mixed> $datos
     * @param list<Visita>|null $visitas Por defecto, la visita de Ana de 10:00 a 11:00 en la Obra A.
     */
    private function validar(array $datos, ?array $visitas = null): ResultadoValidacion
    {
        $visitas ??= [$this->visitaA()];

        return (new ValidadorVisita())->validar($datos, $visitas, self::TECNICOS, self::OBRAS);
    }

    private function assertSinErrores(ResultadoValidacion $resultado): void
    {
        self::assertSame([], array_map(fn (ErrorRegla $e) => "{$e->regla}: {$e->mensaje}", $resultado->errores));
        self::assertTrue($resultado->esValida());
        self::assertNotNull($resultado->visita);
    }

    /** @param list<array{string, string}> $esperados */
    private function assertErrores(array $esperados, ResultadoValidacion $resultado): void
    {
        self::assertSame($esperados, array_map(fn (ErrorRegla $e) => [$e->regla, $e->mensaje], $resultado->errores));
        self::assertFalse($resultado->esValida());
    }
}
