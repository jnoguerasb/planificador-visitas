<?php

declare(strict_types=1);

namespace App;

use Closure;
use LogicException;

/**
 * Una entrada de la tabla de rutas: método, patrón de la ruta, quién puede usarla y qué hace.
 * Las rutas públicas se declaran a propósito con Ruta::publica; las demás deben decir sus roles.
 */
final readonly class Ruta
{
    /**
     * @param list<string>|null $roles null = pública.
     * @param Closure(Peticion): Respuesta $accion
     */
    private function __construct(
        public string $metodo,
        public string $patron,
        public ?array $roles,
        public Closure $accion,
        public bool $csrf,
    ) {
    }

    /** @param callable(Peticion): Respuesta $accion */
    public static function publica(string $metodo, string $patron, callable $accion, bool $csrf = true): self
    {
        return new self($metodo, $patron, null, $accion(...), $csrf);
    }

    /**
     * @param list<string> $roles
     * @param callable(Peticion): Respuesta $accion
     */
    public static function para(array $roles, string $metodo, string $patron, callable $accion): self
    {
        if ($roles === [] || array_diff($roles, [Usuario::PLANIFICADOR, Usuario::TECNICO]) !== []) {
            throw new LogicException("Roles no válidos para la ruta {$metodo} {$patron}");
        }

        return new self($metodo, $patron, $roles, $accion(...), true);
    }

    public function esPublica(): bool
    {
        return $this->roles === null;
    }
}
