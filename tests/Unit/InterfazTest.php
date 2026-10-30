<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Comprobaciones estáticas de la interfaz (public/):
 * - nada que la Content-Security-Policy (default-src 'self') bloquearía: ni JavaScript ni estilos
 *   dentro del HTML, ni recursos de otros dominios;
 * - todo archivo referenciado existe con las mismas mayúsculas, porque en producción (Linux)
 *   las rutas distinguen mayúsculas y en Windows no.
 */
final class InterfazTest extends TestCase
{
    private const PUBLICA = __DIR__ . '/../../public';

    public function testElHtmlNoLlevaCodigoNiEstilosEnLinea(): void
    {
        $html = $this->leer('index.html');

        self::assertSame(0, preg_match('/<script(?![^>]*\bsrc=)[^>]*>/i', $html), 'Todo <script> debe cargar un archivo (src)');
        self::assertSame(0, preg_match('/<style\b/i', $html), 'Sin bloques <style>');
        self::assertSame(0, preg_match('/\sstyle\s*=/i', $html), 'Sin atributos style');
        self::assertSame(0, preg_match('/\son[a-z]+\s*=/i', $html), 'Sin manejadores onclick=, onload=...');
        self::assertStringNotContainsStringIgnoringCase('javascript:', $html);
    }

    public function testElHtmlSoloReferenciaArchivosPropiosQueExisten(): void
    {
        preg_match_all('/\s(?:src|href)="([^"]+)"/i', $this->leer('index.html'), $coincidencias);

        self::assertNotEmpty($coincidencias[1]);
        foreach ($coincidencias[1] as $ruta) {
            self::assertStringStartsWith('/', $ruta, "Solo rutas propias: {$ruta}");
            $this->assertExisteConMayusculasExactas(ltrim($ruta, '/'));
        }
    }

    /** @return array<string, array{string}> */
    public static function archivosJs(): array
    {
        $archivos = [];
        foreach (glob(self::PUBLICA . '/js/*.js') ?: [] as $ruta) {
            $archivos[basename($ruta)] = ['js/' . basename($ruta)];
        }

        return $archivos;
    }

    #[DataProvider('archivosJs')]
    public function testLosImportsDeCadaModuloExistenConMayusculasExactas(string $archivo): void
    {
        preg_match_all('/\bfrom\s+[\'"]([^\'"]+)[\'"]/', $this->leer($archivo), $coincidencias);

        foreach ($coincidencias[1] as $importado) {
            self::assertStringStartsWith('./', $importado, "{$archivo}: import relativo esperado");
            $this->assertExisteConMayusculasExactas('js/' . substr($importado, 2));
        }
    }

    #[DataProvider('archivosJs')]
    public function testNingunModuloCargaRecursosDeOtrosDominios(string $archivo): void
    {
        self::assertSame(0, preg_match('#https?://#i', $this->leer($archivo)), "{$archivo} no debe usar URLs externas");
    }

    public function testLaHojaDeEstilosNoCargaRecursosDeOtrosDominios(): void
    {
        $css = $this->leer('css/estilos.css');

        self::assertSame(0, preg_match('#https?://|@import#i', $css));
    }

    private function leer(string $ruta): string
    {
        $contenido = file_get_contents(self::PUBLICA . '/' . $ruta);
        self::assertIsString($contenido, "No se puede leer public/{$ruta}");

        return $contenido;
    }

    /** Comprueba carpeta a carpeta con scandir, que devuelve los nombres reales con sus mayúsculas. */
    private function assertExisteConMayusculasExactas(string $relativa): void
    {
        $carpeta = self::PUBLICA;
        foreach (explode('/', $relativa) as $parte) {
            $entradas = is_dir($carpeta) ? (scandir($carpeta) ?: []) : [];
            self::assertContains($parte, $entradas, "public/{$relativa} no existe (o cambian las mayúsculas)");
            $carpeta .= '/' . $parte;
        }
    }
}
