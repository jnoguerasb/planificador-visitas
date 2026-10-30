<?php

declare(strict_types=1);

namespace App;

use DateTimeImmutable;

/**
 * Reglas para completar y cancelar una visita. Clase pura: no toca la base de datos.
 *
 * Orden: PERMISO (va solo, para no revelar nada de visitas ajenas), luego ESTADO (va solo) y
 * por último MOMENTO y TEXTO, que se devuelven juntos.
 */
final class ValidadorCierre
{
    private const PERMISO = 'No tienes permiso para modificar esta visita';

    public function __construct(private readonly Permisos $permisos = new Permisos())
    {
    }

    /** @return list<ErrorRegla> */
    public function completar(Usuario $usuario, Visita $visita, mixed $comentario, DateTimeImmutable $ahora): array
    {
        if (!$this->permisos->puedeCompletar($usuario, $visita)) {
            return [new ErrorRegla(ErrorRegla::PERMISO, self::PERMISO)];
        }
        if ($visita->estado !== Visita::PROGRAMADA) {
            return [$this->errorEstado($visita)];
        }

        $errores = [];

        $comienzo = new DateTimeImmutable($visita->fecha . ' ' . Horas::aTexto($visita->inicio), $ahora->getTimezone());
        if ($ahora < $comienzo) {
            $errores[] = new ErrorRegla(
                ErrorRegla::MOMENTO,
                'Podrás completar esta visita a partir del ' . $comienzo->format('d/m') . ' a las ' . $comienzo->format('H:i')
            );
        }

        $error = $this->validarTexto(
            $comentario,
            Reglas::COMENTARIO_MINIMO,
            'comentario',
            sprintf('Describe el trabajo realizado (mínimo %d caracteres)', Reglas::COMENTARIO_MINIMO),
            sprintf('El comentario no puede superar %d caracteres', Reglas::TEXTO_MAXIMO)
        );
        if ($error !== null) {
            $errores[] = $error;
        }

        return $errores;
    }

    /** @return list<ErrorRegla> */
    public function cancelar(Usuario $usuario, Visita $visita, mixed $motivo): array
    {
        if (!$this->permisos->puedeCancelar($usuario, $visita)) {
            return [new ErrorRegla(ErrorRegla::PERMISO, self::PERMISO)];
        }
        if ($visita->estado !== Visita::PROGRAMADA) {
            return [$this->errorEstado($visita)];
        }

        $error = $this->validarTexto(
            $motivo,
            Reglas::MOTIVO_MINIMO,
            'motivo',
            sprintf('Indica el motivo de la cancelación (mínimo %d caracteres)', Reglas::MOTIVO_MINIMO),
            sprintf('El motivo no puede superar %d caracteres', Reglas::TEXTO_MAXIMO)
        );

        return $error === null ? [] : [$error];
    }

    private function errorEstado(Visita $visita): ErrorRegla
    {
        return new ErrorRegla(ErrorRegla::ESTADO, "La visita ya está {$visita->estado}");
    }

    /**
     * El mínimo se cuenta sin los espacios de los extremos, para que unos espacios no pasen por texto.
     * El valor no se modifica: se guarda tal como llega.
     */
    private function validarTexto(mixed $texto, int $minimo, string $campo, string $mensajeMinimo, string $mensajeMaximo): ?ErrorRegla
    {
        if (!is_string($texto) || mb_strlen(trim($texto)) < $minimo) {
            return new ErrorRegla(ErrorRegla::TEXTO, $mensajeMinimo, $campo);
        }
        if (mb_strlen($texto) > Reglas::TEXTO_MAXIMO) {
            return new ErrorRegla(ErrorRegla::TEXTO, $mensajeMaximo, $campo);
        }

        return null;
    }
}
