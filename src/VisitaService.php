<?php

declare(strict_types=1);

namespace App;

use PDO;
use Throwable;

/**
 * Operaciones sobre visitas. Cada escritura va en una transacción que empieza bloqueando al
 * técnico (FOR UPDATE): dos escrituras sobre el mismo técnico nunca se validan a la vez.
 *
 * Si se incumple una regla se lanza ErroresDeNegocio con todos los errores y no se guarda nada.
 */
final class VisitaService
{
    /** Mayor id posible en PostgreSQL (INT). Uno mayor no se consulta: no existe. */
    private const ID_MAXIMO = 2_147_483_647;

    private readonly CatalogoRepository $catalogo;
    private readonly VisitaRepository $visitas;
    private readonly Permisos $permisos;

    public function __construct(private readonly PDO $pdo, private readonly Reloj $reloj = new Reloj())
    {
        $this->catalogo = new CatalogoRepository($pdo);
        $this->visitas = new VisitaRepository($pdo);
        $this->permisos = new Permisos();
    }

    /**
     * Guarda la visita nueva y, si hay una siguiente, la hace salir de la nueva. Devuelve el id.
     *
     * @param array<string, mixed> $datos Los del formulario (ver ValidadorVisita::validar).
     */
    public function programar(Usuario $usuario, array $datos): int
    {
        $this->exigirPermiso($this->permisos->puedeProgramar($usuario), 'No tienes permiso para programar visitas');

        return $this->enTransaccion(function () use ($usuario, $datos): int {
            $resultado = $this->validarContraLaBase($datos, true);
            if (!$resultado->esValida() || $resultado->visita === null) {
                throw new ErroresDeNegocio($resultado->errores);
            }

            $id = $this->visitas->insertar($resultado->visita, $usuario->id, $this->reloj->ahora());
            if ($resultado->siguiente?->id !== null && $resultado->desplazamientoSiguiente !== null) {
                $this->visitas->enlazarSiguiente($resultado->siguiente->id, $id, $resultado->desplazamientoSiguiente);
            }

            return $id;
        });
    }

    /**
     * La misma validación que programar, sin escribir nada. Para la previsualización.
     *
     * @param array<string, mixed> $datos
     */
    public function validar(Usuario $usuario, array $datos): ResultadoValidacion
    {
        $this->exigirPermiso($this->permisos->puedeProgramar($usuario), 'No tienes permiso para programar visitas');

        return $this->validarContraLaBase($datos, false);
    }

    public function completar(Usuario $usuario, int $visitaId, mixed $comentario): void
    {
        $this->enTransaccion(function () use ($usuario, $visitaId, $comentario): void {
            $visita = $this->visitaConTecnicoBloqueado($visitaId);
            $ahora = $this->reloj->ahora();

            $errores = (new ValidadorCierre($this->permisos))->completar($usuario, $visita, $comentario, $ahora);
            if ($errores !== []) {
                throw new ErroresDeNegocio($errores);
            }

            $this->visitas->completar($visitaId, $comentario, $usuario->id, $ahora);
        });
    }

    /** Cancela y, si otra visita salía de esta, le reasigna el origen. Nunca se bloquea por eso. */
    public function cancelar(Usuario $usuario, int $visitaId, mixed $motivo): void
    {
        $this->enTransaccion(function () use ($usuario, $visitaId, $motivo): void {
            $visita = $this->visitaConTecnicoBloqueado($visitaId);

            $errores = (new ValidadorCierre($this->permisos))->cancelar($usuario, $visita, $motivo);
            if ($errores !== []) {
                throw new ErroresDeNegocio($errores);
            }

            $this->visitas->cancelar($visitaId, $motivo, $usuario->id, $this->reloj->ahora());

            $cambio = (new ReasignadorOrigen())->trasCancelar($visita, $this->visitas->delDia($visita->tecnicoId, $visita->fecha));
            if ($cambio !== null) {
                $this->visitas->reasignarOrigen($cambio);
            }
        });
    }

    public function agenda(Usuario $usuario, int $tecnicoId, string $fecha): Agenda
    {
        $this->exigirPermiso($this->permisos->puedeVerAgenda($usuario, $tecnicoId), 'No tienes permiso para ver esta agenda');

        $errores = [];
        $nombre = $this->esIdValido($tecnicoId) ? $this->catalogo->nombreTecnico($tecnicoId) : null;
        if ($nombre === null) {
            $errores[] = new ErrorRegla(ErrorRegla::CAMPOS, 'Elige un técnico válido', 'tecnico_id');
        }
        if (!ValidadorVisita::esFechaValida($fecha)) {
            $errores[] = new ErrorRegla(ErrorRegla::CAMPOS, 'La fecha no es válida (formato AAAA-MM-DD)', 'fecha');
        }
        if ($errores !== [] || $nombre === null) {
            throw new ErroresDeNegocio($errores);
        }

        $visitas = $this->visitas->delDia($tecnicoId, $fecha);

        return new Agenda($tecnicoId, $nombre, $fecha, $visitas, (new CalculadoraAgenda())->huecos($visitas));
    }

    /**
     * Listado con filtros opcionales. Al técnico se le fuerzan sus visitas, pida lo que pida.
     *
     * @return list<array<string, mixed>> Ver VisitaRepository::listar.
     */
    public function listar(Usuario $usuario, ?int $tecnicoId, ?string $fecha, ?string $estado): array
    {
        $this->exigirPermiso($this->permisos->puedeConsultarVisitas($usuario), 'No tienes permiso para consultar visitas');
        $tecnicoId = $this->permisos->tecnicoParaConsulta($usuario, $tecnicoId);

        $errores = [];
        if ($tecnicoId !== null && !$this->esIdValido($tecnicoId)) {
            $errores[] = new ErrorRegla(ErrorRegla::CAMPOS, 'Elige un técnico válido', 'tecnico_id');
        }
        if ($fecha !== null && !ValidadorVisita::esFechaValida($fecha)) {
            $errores[] = new ErrorRegla(ErrorRegla::CAMPOS, 'La fecha no es válida (formato AAAA-MM-DD)', 'fecha');
        }
        if ($estado !== null && !in_array($estado, [Visita::PROGRAMADA, Visita::COMPLETADA, Visita::CANCELADA], true)) {
            $errores[] = new ErrorRegla(ErrorRegla::CAMPOS, 'El estado no es válido', 'estado');
        }
        if ($errores !== []) {
            throw new ErroresDeNegocio($errores);
        }

        return $this->visitas->listar($tecnicoId, $fecha, $estado);
    }

    /**
     * Lee de la base lo que necesita el validador. Solo consulta las visitas con un técnico activo
     * y una fecha real; si no, el validador ya devolverá el error de CAMPOS.
     *
     * @param array<string, mixed> $datos
     */
    private function validarContraLaBase(array $datos, bool $bloquear): ResultadoValidacion
    {
        $tecnicos = $this->catalogo->tecnicosActivos();
        $tecnicoId = $datos['tecnico_id'] ?? null;
        $fecha = $datos['fecha'] ?? null;

        $visitasDelDia = [];
        if (is_int($tecnicoId) && array_key_exists($tecnicoId, $tecnicos) && ValidadorVisita::esFechaValida($fecha)) {
            if ($bloquear) {
                $this->catalogo->bloquearTecnico($tecnicoId);
            }
            $visitasDelDia = $this->visitas->delDia($tecnicoId, $fecha);
        }

        return (new ValidadorVisita())->validar($datos, $visitasDelDia, $tecnicos, $this->catalogo->nombresObras());
    }

    /**
     * Bloquea al técnico de la visita y la vuelve a leer: mientras se esperaba el bloqueo,
     * otra transacción pudo cambiarla.
     */
    private function visitaConTecnicoBloqueado(int $id): Visita
    {
        $visita = $this->esIdValido($id) ? $this->visitas->buscar($id) : null;
        if ($visita === null) {
            throw new VisitaNoEncontrada($id);
        }
        $this->catalogo->bloquearTecnico($visita->tecnicoId);

        return $this->visitas->buscar($id) ?? throw new VisitaNoEncontrada($id);
    }

    /**
     * @template T
     * @param callable(): T $operacion
     * @return T
     */
    private function enTransaccion(callable $operacion): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $resultado = $operacion();
            $this->pdo->commit();

            return $resultado;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function exigirPermiso(bool $permitido, string $mensaje): void
    {
        if (!$permitido) {
            throw ErroresDeNegocio::una(ErrorRegla::PERMISO, $mensaje);
        }
    }

    private function esIdValido(int $id): bool
    {
        return $id >= 1 && $id <= self::ID_MAXIMO;
    }
}
