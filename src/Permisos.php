<?php

declare(strict_types=1);

namespace App;

use LogicException;

/**
 * Qué puede hacer cada rol sobre cada visita (sección 3, tabla de permisos). Deniega por defecto:
 * un rol desconocido, o un técnico sin ficha de técnico, no puede hacer nada.
 */
final class Permisos
{
    public function puedeProgramar(Usuario $usuario): bool
    {
        return $usuario->esPlanificador();
    }

    public function puedeConsultarVisitas(Usuario $usuario): bool
    {
        return $usuario->esPlanificador() || $usuario->esTecnico();
    }

    /**
     * Técnico cuyas visitas se consultan. Al técnico se le fuerza el suyo, pida lo que pida.
     * Para el planificador, null significa "todos".
     */
    public function tecnicoParaConsulta(Usuario $usuario, ?int $tecnicoPedido): ?int
    {
        if ($usuario->esPlanificador()) {
            return $tecnicoPedido;
        }
        if ($usuario->esTecnico()) {
            return $usuario->tecnicoId;
        }

        throw new LogicException('Usuario sin permiso para consultar visitas');
    }

    public function puedeVerAgenda(Usuario $usuario, int $tecnicoId): bool
    {
        return $usuario->esPlanificador() || $this->esSuya($usuario, $tecnicoId);
    }

    /** Solo el técnico de la visita. El estado y el momento los comprueba ValidadorCierre. */
    public function puedeCompletar(Usuario $usuario, Visita $visita): bool
    {
        return $this->esSuya($usuario, $visita->tecnicoId);
    }

    public function puedeCancelar(Usuario $usuario, Visita $visita): bool
    {
        return $usuario->esPlanificador() || $this->esSuya($usuario, $visita->tecnicoId);
    }

    private function esSuya(Usuario $usuario, int $tecnicoId): bool
    {
        return $usuario->esTecnico() && $usuario->tecnicoId === $tecnicoId;
    }
}
