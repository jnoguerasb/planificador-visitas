// Ayudas para construir la página de forma segura: los datos siempre van como texto
// (textContent / setAttribute), nunca como HTML.

/**
 * Crea un elemento. opciones: { clase, texto, atributos: { nombre: valor } }.
 * hijos: elementos o textos que se añaden dentro (los textos se añaden como texto, no como HTML).
 */
export function crear(etiqueta, opciones = {}, hijos = []) {
    const elemento = document.createElement(etiqueta);
    if (opciones.clase) {
        elemento.className = opciones.clase;
    }
    if (opciones.texto !== undefined && opciones.texto !== null) {
        elemento.textContent = String(opciones.texto);
    }
    for (const [nombre, valor] of Object.entries(opciones.atributos ?? {})) {
        elemento.setAttribute(nombre, String(valor));
    }
    for (const hijo of hijos) {
        if (hijo !== null && hijo !== undefined && hijo !== false) {
            elemento.append(hijo);
        }
    }
    return elemento;
}

export function porId(id) {
    return document.getElementById(id);
}

/** "2026-11-03" → "03/11/2026" */
export function fechaLegible(fecha) {
    const [anio, mes, dia] = String(fecha).split('-');
    return `${dia}/${mes}/${anio}`;
}

/** Fecha de hoy en el navegador, "AAAA-MM-DD" (solo para proponer un valor inicial). */
export function hoy() {
    const ahora = new Date();
    const dosCifras = (n) => String(n).padStart(2, '0');
    return `${ahora.getFullYear()}-${dosCifras(ahora.getMonth() + 1)}-${dosCifras(ahora.getDate())}`;
}

/** Suma días a una fecha "AAAA-MM-DD". */
export function sumarDias(fecha, dias) {
    const [anio, mes, dia] = fecha.split('-').map(Number);
    const resultado = new Date(anio, mes - 1, dia + dias);
    const dosCifras = (n) => String(n).padStart(2, '0');
    return `${resultado.getFullYear()}-${dosCifras(resultado.getMonth() + 1)}-${dosCifras(resultado.getDate())}`;
}

/** Pinta una lista de errores {regla, mensaje}; la oculta si no hay ninguno. */
export function pintarErrores(lista, errores) {
    lista.replaceChildren(...errores.map((error) => crear('li', { texto: error.mensaje })));
    lista.hidden = errores.length === 0;
}

/**
 * Coloca cada error junto a su campo (elemento con data-error-de="campo") y los que no
 * tienen campo, en la lista general.
 */
export function repartirErrores(formulario, lista, errores) {
    for (const hueco of formulario.querySelectorAll('[data-error-de]')) {
        hueco.textContent = '';
        hueco.hidden = true;
    }
    for (const campo of formulario.querySelectorAll('[aria-invalid]')) {
        campo.removeAttribute('aria-invalid');
    }

    const generales = [];
    for (const error of errores) {
        const hueco = error.campo
            ? formulario.querySelector(`[data-error-de="${CSS.escape(error.campo)}"]`)
            : null;
        if (hueco === null) {
            generales.push(error);
            continue;
        }
        hueco.textContent = hueco.textContent ? `${hueco.textContent} ${error.mensaje}` : error.mensaje;
        hueco.hidden = false;
        hueco.parentElement?.querySelector('input, select, textarea')?.setAttribute('aria-invalid', 'true');
    }
    pintarErrores(lista, generales);
}

/**
 * Ventana para escribir un texto obligatorio (motivo o comentario). El texto lo valida el
 * servidor: si responde con errores, se muestran y la ventana sigue abierta.
 * enviar(texto) debe devolver una promesa. Devuelve el resultado de enviar, o null si se cierra.
 */
export function pedirTexto({ titulo, descripcion, etiqueta, maximo, confirmar, enviar }) {
    const dialogo = porId('dialogo-texto');
    const formulario = porId('dialogo-form');
    const campo = porId('dialogo-texto-campo');
    const contador = porId('dialogo-contador');
    const errores = porId('dialogo-errores');
    const botonConfirmar = porId('dialogo-confirmar');

    porId('dialogo-titulo').textContent = titulo;
    porId('dialogo-descripcion').textContent = descripcion;
    porId('dialogo-etiqueta').textContent = etiqueta;
    botonConfirmar.textContent = confirmar;
    campo.value = '';
    campo.setAttribute('maxlength', String(maximo));
    pintarErrores(errores, []);

    const actualizarContador = () => {
        contador.textContent = `${campo.value.length} / ${maximo} caracteres`;
    };
    actualizarContador();

    // Todos los manejadores se quitan juntos al cerrar, para no acumularlos.
    const control = new AbortController();
    return new Promise((resolver) => {
        campo.addEventListener('input', actualizarContador, { signal: control.signal });
        porId('dialogo-volver').addEventListener('click', () => dialogo.close(), { signal: control.signal });
        dialogo.addEventListener('close', () => {
            control.abort();
            resolver(null);
        }, { signal: control.signal });
        formulario.addEventListener('submit', async (evento) => {
            evento.preventDefault();
            botonConfirmar.disabled = true;
            try {
                const resultado = await enviar(campo.value);
                resolver(resultado);
                dialogo.close();
            } catch (error) {
                pintarErrores(errores, error.errores ?? [{ mensaje: 'Ha ocurrido un error. Inténtalo de nuevo.' }]);
            } finally {
                botonConfirmar.disabled = false;
            }
        }, { signal: control.signal });

        dialogo.showModal();
        campo.focus();
    });
}
