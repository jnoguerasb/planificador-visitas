// Dibuja la agenda de un día (respuesta de GET /api/agenda): la franja de 08:00 a 17:00 con
// desplazamientos (rayado), visitas (color y texto) y huecos libres, y debajo la misma
// información en texto, para no depender del color. Compartido por planificación y Mi agenda.

import { crear } from './dom.js';

export function aMinutos(hora) {
    const [horas, minutos] = String(hora).split(':').map(Number);
    return horas * 60 + minutos;
}

export function aHora(minutos) {
    const dosCifras = (n) => String(n).padStart(2, '0');
    return `${dosCifras(Math.floor(minutos / 60))}:${dosCifras(minutos % 60)}`;
}

/**
 * propuesta (opcional): { inicio, fin, salida, cabe } en "HH:MM", para la visita que se está
 * programando. Si cabe o no lo dice el servidor (POST /api/visitas/validar), no este código.
 */
export function dibujarAgenda(contenedor, agenda, propuesta = null) {
    const inicioJornada = aMinutos(agenda.jornada.inicio);
    const finJornada = aMinutos(agenda.jornada.fin);
    const total = finJornada - inicioJornada;

    const franja = crear('div', { clase: 'franja', atributos: { 'aria-hidden': 'true' } });
    const colocar = (elemento, desde, hasta) => {
        const a = Math.max(desde, inicioJornada);
        const b = Math.min(hasta, finJornada);
        elemento.style.left = `${((a - inicioJornada) / total) * 100}%`;
        elemento.style.width = `${(Math.max(0, b - a) / total) * 100}%`;
        return elemento;
    };

    for (let minuto = inicioJornada; minuto <= finJornada; minuto += 60) {
        const marca = crear('span', { clase: 'franja-hora', texto: aHora(minuto) });
        marca.style.left = `${((minuto - inicioJornada) / total) * 100}%`;
        franja.append(marca);
    }

    for (const hueco of agenda.huecos) {
        franja.append(colocar(
            crear('div', { clase: 'franja-bloque franja-hueco', texto: `${hueco.minutos} min` }),
            aMinutos(hueco.inicio),
            aMinutos(hueco.fin)
        ));
    }

    for (const visita of agenda.visitas) {
        if (visita.estado === 'Cancelada') {
            continue;
        }
        const descripcion = `${visita.inicio}–${visita.fin} ${visita.obra} (${visita.estado})`;
        franja.append(colocar(
            crear('div', { clase: 'franja-bloque franja-desplazamiento', atributos: { title: `Desplazamiento de ${visita.desplazamiento_min} min` } }),
            aMinutos(visita.salida),
            aMinutos(visita.inicio)
        ));
        franja.append(colocar(
            crear('div', { clase: `franja-bloque franja-visita estado-${visita.estado.toLowerCase()}`, texto: visita.obra, atributos: { title: descripcion } }),
            aMinutos(visita.inicio),
            aMinutos(visita.fin)
        ));
    }

    if (propuesta) {
        franja.append(colocar(
            crear('div', {
                clase: `franja-bloque franja-propuesta ${propuesta.cabe ? 'cabe' : 'no-cabe'}`,
                texto: propuesta.cabe ? 'Nueva: cabe' : 'Nueva: no cabe',
            }),
            aMinutos(propuesta.salida),
            aMinutos(propuesta.fin)
        ));
    }

    contenedor.replaceChildren(franja, listaEnTexto(agenda, propuesta), textoHuecos(agenda.huecos));
}

function listaEnTexto(agenda, propuesta) {
    const lista = crear('ol', { clase: 'agenda-lista', atributos: { 'aria-label': `Visitas del ${agenda.fecha}` } });

    for (const visita of agenda.visitas) {
        lista.append(crear('li', {}, [
            `${visita.inicio}–${visita.fin} · ${visita.obra} · `,
            crear('span', { clase: `estado estado-${visita.estado.toLowerCase()}`, texto: visita.estado }),
            ` · ${visita.origen}, ${visita.desplazamiento_min} min de desplazamiento`,
            visita.revisar_desplazamiento ? crear('span', { clase: 'etiqueta-revisar', texto: 'Revisar desplazamiento' }) : null,
        ]));
    }
    if (agenda.visitas.length === 0) {
        lista.append(crear('li', { texto: 'Sin visitas este día.' }));
    }
    if (propuesta) {
        lista.append(crear('li', {
            clase: propuesta.cabe ? 'agenda-propuesta-cabe' : 'agenda-propuesta-no-cabe',
            texto: `Visita propuesta ${propuesta.inicio}–${propuesta.fin} (sale a las ${propuesta.salida}): ${propuesta.cabe ? 'cabe' : 'no cabe'}`,
        }));
    }
    return lista;
}

function textoHuecos(huecos) {
    const texto = huecos.length === 0
        ? 'Sin huecos libres.'
        : `Huecos libres: ${huecos.map((h) => `${h.inicio}–${h.fin} (${h.minutos} min)`).join(', ')}.`;
    return crear('p', { clase: 'ayuda', texto });
}
