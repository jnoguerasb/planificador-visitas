<?php

declare(strict_types=1);

namespace App;

/**
 * Token anti-CSRF: se genera al iniciar sesión, se guarda en la sesión y el navegador lo envía
 * en la cabecera X-CSRF-Token en cada POST (salvo el login, ver CLAUDE.md, premisa 4).
 */
final class Csrf
{
    public const CABECERA = 'x-csrf-token';

    public static function generar(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** Comparación en tiempo constante, para no dar pistas por lo que tarda. */
    public static function esValido(?string $esperado, mixed $recibido): bool
    {
        return is_string($esperado) && $esperado !== '' && is_string($recibido) && hash_equals($esperado, $recibido);
    }
}
