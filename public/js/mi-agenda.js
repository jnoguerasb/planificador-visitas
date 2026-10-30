// Vista Mi agenda (técnico): un día con navegación, la franja horaria y una tarjeta por visita.
// Los botones Completar y Cancelar solo aparecen si el servidor dice que se pueden usar
// (acciones de GET /api/agenda); el comentario y el motivo los valida el servidor.

import { dibujarAgenda } from './agenda.js';
import { get, post } from './api.js';
import { crear, fechaLegible, hoy, pedirTexto, pintarErrores, porId, sumarDias } from './dom.js';

// Enlace (no recurso cargado por la página) al buscador de OpenStreetMap con la dirección de la obra.
const MAPA = 'https://www.openstreetmap.org/search?query=';
const TEXTO_MAXIMO = 2000;

let iniciada = false;

export async function iniciarMiAgenda() {
    if (!iniciada) {
        iniciada = true;
        const dia = porId('dia-actual');
        dia.value = hoy();
        dia.addEventListener('change', cargar);
        porId('dia-anterior').addEventListener('click', () => cambiarDia(-1));
        porId('dia-siguiente').addEventListener('click', () => cambiarDia(1));
    }
    await cargar();
}

function cambiarDia(dias) {
    const dia = porId('dia-actual');
    dia.value = sumarDias(dia.value || hoy(), dias);
    cargar();
}

async function cargar() {
    const errores = porId('errores-mi-agenda');
    const fecha = porId('dia-actual').value || hoy();
    try {
        // Sin tecnico_id: el servidor usa el técnico del usuario de la sesión.
        const agenda = await get('/api/agenda', { fecha });
        pintarErrores(errores, []);
        dibujarAgenda(porId('agenda-tecnico'), agenda);
        pintarTarjetas(agenda);
    } catch (error) {
        pintarErrores(errores, error.errores ?? []);
    }
}

function pintarTarjetas(agenda) {
    const contenedor = porId('tarjetas-visitas');
    if (agenda.visitas.length === 0) {
        contenedor.replaceChildren(crear('p', { clase: 'ayuda', texto: `No tienes visitas el ${fechaLegible(agenda.fecha)}.` }));
        return;
    }
    contenedor.replaceChildren(...agenda.visitas.map((visita) => tarjeta(agenda.fecha, visita)));
}

function tarjeta(fecha, visita) {
    const direccion = `${visita.direccion}, ${visita.municipio}`;
    const enlaceMapa = crear('a', {
        texto: direccion,
        atributos: { href: MAPA + encodeURIComponent(direccion), target: '_blank', rel: 'noopener noreferrer' },
    });

    const botones = [];
    if (visita.acciones.completar) {
        botones.push(boton('Completar', 'boton', () => completar(fecha, visita)));
    }
    if (visita.acciones.cancelar) {
        botones.push(boton('Cancelar', 'boton boton-peligro', () => cancelar(fecha, visita)));
    }

    return crear('article', { clase: `tarjeta-visita estado-borde-${visita.estado.toLowerCase()}` }, [
        crear('h3', { texto: `${visita.inicio}–${visita.fin} · ${visita.obra}` }),
        crear('p', {}, [enlaceMapa]),
        crear('p', {}, [
            `${visita.origen}, ${visita.desplazamiento_min} min de desplazamiento (salir a las ${visita.salida})`,
            visita.revisar_desplazamiento ? crear('span', { clase: 'etiqueta-revisar', texto: 'Revisar desplazamiento' }) : null,
        ]),
        crear('p', {}, [crear('span', { clase: `estado estado-${visita.estado.toLowerCase()}`, texto: visita.estado })]),
        botones.length > 0 ? crear('div', { clase: 'botonera' }, botones) : null,
    ]);
}

function boton(texto, clase, alPulsar) {
    const elemento = crear('button', { clase, texto, atributos: { type: 'button' } });
    elemento.addEventListener('click', alPulsar);
    return elemento;
}

async function completar(fecha, visita) {
    const hecho = await pedirTexto({
        titulo: 'Completar visita',
        descripcion: `${fechaLegible(fecha)}, ${visita.inicio}–${visita.fin} · ${visita.obra}`,
        etiqueta: 'Trabajo realizado o temas tratados (mínimo 10 caracteres)',
        maximo: TEXTO_MAXIMO,
        confirmar: 'Completar visita',
        enviar: (comentario) => post(`/api/visitas/${visita.id}/completar`, { comentario }),
    });
    if (hecho) {
        await cargar();
    }
}

async function cancelar(fecha, visita) {
    const hecho = await pedirTexto({
        titulo: 'Cancelar visita',
        descripcion: `${fechaLegible(fecha)}, ${visita.inicio}–${visita.fin} · ${visita.obra}`,
        etiqueta: 'Motivo de la cancelación (mínimo 5 caracteres)',
        maximo: TEXTO_MAXIMO,
        confirmar: 'Cancelar visita',
        enviar: (motivo) => post(`/api/visitas/${visita.id}/cancelar`, { motivo }),
    });
    if (hecho) {
        await cargar();
    }
}
