// Llamadas a la API. Envía el token CSRF en los POST y convierte los errores del servidor
// ({"errores": [{regla, mensaje, campo}]}) en un ErrorApi con esa misma lista.

let tokenCsrf = '';

export function fijarToken(token) {
    tokenCsrf = typeof token === 'string' ? token : '';
}

export class ErrorApi extends Error {
    constructor(estado, errores) {
        super(errores[0]?.mensaje ?? 'Ha ocurrido un error. Inténtalo de nuevo.');
        this.estado = estado;
        this.errores = errores;
    }
}

const ERROR_GENERICO = [{ regla: 'INTERNO', mensaje: 'Ha ocurrido un error. Inténtalo de nuevo.' }];
const ERROR_DE_RED = [{ regla: 'INTERNO', mensaje: 'No se ha podido conectar con el servidor. Comprueba la conexión e inténtalo de nuevo.' }];

async function pedir(metodo, ruta, datos) {
    const opciones = { method: metodo, credentials: 'same-origin', headers: { Accept: 'application/json' } };
    if (metodo === 'POST') {
        opciones.headers['Content-Type'] = 'application/json';
        opciones.headers['X-CSRF-Token'] = tokenCsrf;
        opciones.body = JSON.stringify(datos ?? {});
    }

    let respuesta;
    try {
        respuesta = await fetch(ruta, opciones);
    } catch {
        throw new ErrorApi(0, ERROR_DE_RED);
    }

    const cuerpo = respuesta.status === 204 ? null : await respuesta.json().catch(() => null);
    if (respuesta.ok) {
        return cuerpo;
    }

    // Sesión caducada en mitad del trabajo: la aplicación vuelve al login.
    if (respuesta.status === 401 && ruta !== '/api/login' && ruta !== '/api/yo') {
        document.dispatchEvent(new CustomEvent('sesion-caducada'));
    }
    throw new ErrorApi(respuesta.status, Array.isArray(cuerpo?.errores) ? cuerpo.errores : ERROR_GENERICO);
}

/** GET con parámetros; los vacíos no se envían. */
export function get(ruta, parametros = {}) {
    const consulta = new URLSearchParams();
    for (const [nombre, valor] of Object.entries(parametros)) {
        if (valor !== '' && valor !== null && valor !== undefined) {
            consulta.append(nombre, String(valor));
        }
    }
    const texto = consulta.toString();
    return pedir('GET', texto ? `${ruta}?${texto}` : ruta);
}

export function post(ruta, datos) {
    return pedir('POST', ruta, datos);
}
