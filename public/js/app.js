// Arranque: pregunta al servidor quién es el usuario (GET /api/yo) y muestra la vista de su rol.
// El servidor vuelve a comprobar el rol en cada petición; ocultar vistas aquí es solo comodidad.

import { fijarToken, get, post } from './api.js';
import { porId } from './dom.js';
import { iniciarLogin, mostrarLogin } from './login.js';
import { iniciarPlanificacion } from './planificacion.js';

const VISTAS = ['vista-login', 'vista-planificacion', 'vista-mi-agenda'];
let vistaIniciada = null;

function ocultarTodo() {
    for (const id of VISTAS) {
        porId(id).hidden = true;
    }
    porId('sesion').hidden = true;
    porId('cargando').hidden = true;
}

function entrar({ usuario, csrf }) {
    fijarToken(csrf);
    ocultarTodo();
    porId('sesion-nombre').textContent = usuario.rol === 'TECNICO'
        ? `${usuario.tecnico} (técnico)`
        : `${usuario.email} (planificador)`;
    porId('sesion').hidden = false;

    // Al volver a entrar con otro usuario se recarga la página para no mezclar datos.
    if (vistaIniciada !== null && vistaIniciada !== usuario.id) {
        window.location.reload();
        return;
    }
    vistaIniciada = usuario.id;

    if (usuario.rol === 'PLANIFICADOR') {
        porId('vista-planificacion').hidden = false;
        iniciarPlanificacion();
    } else {
        porId('vista-mi-agenda').hidden = false;
        porId('agenda-tecnico').textContent = 'La vista del técnico llega en la Fase 7.';
    }
}

function salirAlLogin(mensaje = '') {
    fijarToken('');
    ocultarTodo();
    mostrarLogin(mensaje);
}

async function arrancar() {
    iniciarLogin(entrar);

    porId('boton-salir').addEventListener('click', async () => {
        try {
            await post('/api/logout');
        } finally {
            // Recargar deja la página limpia, sin datos del usuario anterior.
            window.location.reload();
        }
    });

    document.addEventListener('sesion-caducada', () => {
        salirAlLogin('Tu sesión ha caducado. Vuelve a entrar.');
    });

    try {
        entrar(await get('/api/yo'));
    } catch {
        salirAlLogin();
    }
}

arrancar();
