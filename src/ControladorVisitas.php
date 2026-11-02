<?php

declare(strict_types=1);

namespace App;

/**
 * Endpoints de visitas: lee la petición, llama a VisitaService y da forma a la respuesta.
 * Las reglas no están aquí: los errores de negocio los traduce ManejadorErrores (403, 409, 422).
 */
final class ControladorVisitas
{
    /** Campos de una visita nueva. El origen se admite porque el navegador puede enviarlo, pero se ignora (caso 20). */
    private const CAMPOS_VISITA = [
        'tecnico_id', 'obra_id', 'fecha', 'hora_inicio', 'duracion_min', 'desplazamiento_min',
        'desplazamiento_siguiente_min', 'origen_tipo', 'origen_visita_id',
    ];

    public function __construct(
        private readonly VisitaService $servicio,
        private readonly CatalogoRepository $catalogo,
        private readonly Reloj $reloj = new Reloj(),
    ) {
    }

    /** GET /api/visitas?tecnico_id=&fecha=&estado= */
    public function listar(Peticion $peticion): Respuesta
    {
        $filtros = Entrada::parametros($peticion->query, ['tecnico_id', 'fecha', 'estado']);
        $tecnicoId = Entrada::entero($filtros['tecnico_id'] ?? null, 'tecnico_id', 'Elige un técnico válido');

        return Respuesta::json(200, ['visitas' => $this->servicio->listar(
            $this->usuario($peticion),
            $tecnicoId,
            $filtros['fecha'] ?? null,
            $filtros['estado'] ?? null
        )]);
    }

    /** GET /api/agenda?tecnico_id=&fecha=. El técnico puede omitir tecnico_id: se usa el suyo. */
    public function agenda(Peticion $peticion): Respuesta
    {
        $usuario = $this->usuario($peticion);
        $filtros = Entrada::parametros($peticion->query, ['tecnico_id', 'fecha']);
        $tecnicoId = Entrada::entero($filtros['tecnico_id'] ?? null, 'tecnico_id', 'Elige un técnico válido')
            ?? ($usuario->esTecnico() ? $usuario->tecnicoId : null)
            ?? 0;

        $agenda = $this->servicio->agenda($usuario, $tecnicoId, $filtros['fecha'] ?? '');

        $obras = array_column($this->catalogo->obras(), null, 'id');
        $porId = [];
        foreach ($agenda->visitas as $visita) {
            $porId[$visita->id] = $visita;
        }
        $ahora = $this->reloj->ahora();
        $cierre = new ValidadorCierre();

        $visitas = [];
        foreach ($agenda->visitas as $visita) {
            $anterior = $visita->origenVisitaId === null ? null : ($porId[$visita->origenVisitaId] ?? null);
            $visitas[] = [
                'id' => $visita->id,
                'obra_id' => $visita->obraId,
                'obra' => $visita->obraNombre,
                'direccion' => $obras[$visita->obraId]['direccion'] ?? '',
                'municipio' => $obras[$visita->obraId]['municipio'] ?? '',
                'inicio' => Horas::aTexto($visita->inicio),
                'fin' => Horas::aTexto($visita->fin()),
                'salida' => Horas::aTexto(max(0, $visita->salida())),
                'desplazamiento_min' => $visita->desplazamiento,
                'origen_tipo' => $visita->origenTipo(),
                'origen_visita_id' => $visita->origenVisitaId,
                'origen' => $visita->origenVisitaId !== null && $anterior === null ? 'Desde otra visita' : self::textoOrigen($anterior),
                'estado' => $visita->estado,
                'revisar_desplazamiento' => $visita->revisarDesplazamiento,
                // Los botones los decide el servidor con las mismas reglas que al completar o cancelar.
                'acciones' => [
                    'completar' => $cierre->completar($usuario, $visita, str_repeat('x', Reglas::COMENTARIO_MINIMO), $ahora) === [],
                    'cancelar' => $cierre->cancelar($usuario, $visita, str_repeat('x', Reglas::MOTIVO_MINIMO)) === [],
                ],
            ];
        }

        return Respuesta::json(200, [
            'tecnico' => ['id' => $agenda->tecnicoId, 'nombre' => $agenda->tecnicoNombre],
            'fecha' => $agenda->fecha,
            'jornada' => ['inicio' => Horas::aTexto(Reglas::JORNADA_INICIO), 'fin' => Horas::aTexto(Reglas::JORNADA_FIN)],
            'visitas' => $visitas,
            'huecos' => array_map(
                fn (Hueco $h) => ['inicio' => $h->inicioTexto(), 'fin' => $h->finTexto(), 'minutos' => $h->minutos()],
                $agenda->huecos
            ),
        ]);
    }

    /**
     * POST /api/visitas/validar. Siempre 200 si la petición se puede leer: los errores de las reglas
     * son el resultado de la previsualización, junto con el origen calculado, el desplazamiento
     * mínimo y la visita siguiente.
     */
    public function validar(Peticion $peticion): Respuesta
    {
        $datos = $peticion->json(self::CAMPOS_VISITA);
        $resultado = $this->servicio->validar($this->usuario($peticion), $datos);

        $origen = match ($resultado->origenTipo()) {
            null => null,
            default => [
                'tipo' => $resultado->origenTipo(),
                'visita_id' => $resultado->origenVisitaId(),
                'texto' => self::textoOrigen($resultado->anterior),
            ],
        };

        $siguiente = $resultado->siguiente === null ? null : [
            'id' => $resultado->siguiente->id,
            'obra' => $resultado->siguiente->obraNombre,
            'inicio' => Horas::aTexto($resultado->siguiente->inicio),
            // Valor propuesto: 0 si es la misma obra; si no, el que ya tenía, como mínimo 30.
            'desplazamiento_min' => $resultado->desplazamientoSiguienteSugerido ?? $resultado->siguiente->desplazamiento,
            // El campo "desplazamiento hasta la siguiente" solo se pide si es en otra obra.
            'pedir_desplazamiento' => $resultado->siguiente->obraId !== ($datos['obra_id'] ?? null),
        ];

        return Respuesta::json(200, [
            'valida' => $resultado->esValida(),
            'origen' => $origen,
            // Mínimo para llegar a la nueva: 0 desde la sede o en la misma obra, 30 desde otra obra.
            'desplazamiento_minimo' => $resultado->desplazamientoMinimo,
            'siguiente' => $siguiente,
            'errores' => Respuesta::listaDeErrores($resultado->errores),
        ]);
    }

    /** POST /api/visitas */
    public function programar(Peticion $peticion): Respuesta
    {
        $id = $this->servicio->programar($this->usuario($peticion), $peticion->json(self::CAMPOS_VISITA));

        return Respuesta::json(201, ['id' => $id]);
    }

    /** POST /api/visitas/{id}/completar con {"comentario"} */
    public function completar(Peticion $peticion): Respuesta
    {
        $id = $this->idDeLaRuta($peticion);
        $datos = $peticion->json(['comentario']);
        $this->servicio->completar($this->usuario($peticion), $id, $datos['comentario'] ?? null);

        return Respuesta::json(200, ['id' => $id, 'estado' => Visita::COMPLETADA]);
    }

    /** POST /api/visitas/{id}/cancelar con {"motivo"} */
    public function cancelar(Peticion $peticion): Respuesta
    {
        $id = $this->idDeLaRuta($peticion);
        $datos = $peticion->json(['motivo']);
        $this->servicio->cancelar($this->usuario($peticion), $id, $datos['motivo'] ?? null);

        return Respuesta::json(200, ['id' => $id, 'estado' => Visita::CANCELADA]);
    }

    /** "Desde la sede" o "Desde Obra X (acaba 11:00)". */
    private static function textoOrigen(?Visita $anterior): string
    {
        return $anterior === null
            ? 'Desde la sede'
            : sprintf('Desde %s (acaba %s)', $anterior->obraNombre, Horas::aTexto($anterior->fin()));
    }

    /** El patrón de la ruta ya garantiza de 1 a 18 cifras sin ceros a la izquierda. */
    private function idDeLaRuta(Peticion $peticion): int
    {
        return (int) ($peticion->parametros['id'] ?? 0);
    }

    /** Las rutas de este controlador son privadas: el router ya ha comprobado la sesión. */
    private function usuario(Peticion $peticion): Usuario
    {
        return $peticion->usuario ?? throw ErrorHttp::noAutenticado();
    }
}
