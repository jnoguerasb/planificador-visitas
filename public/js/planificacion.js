// Vista Planificación: formulario de nueva visita con previsualización, agenda del técnico,
// filtros y listado con cancelación.
//
// Ninguna regla de negocio vive aquí: el origen, si la visita cabe y los errores los calcula el
// servidor (POST /api/visitas/validar, la misma lógica que al guardar). Este código solo los muestra.

import { aHora, aMinutos, dibujarAgenda } from './agenda.js';
import { get, post } from './api.js';
import { crear, fechaLegible, hoy, pedirTexto, pintarErrores, porId, repartirErrores } from './dom.js';

const ESPERA_PREVISUALIZACION_MS = 400;

let iniciada = false;
let temporizador = null;
let numeroPeticion = 0;          // para descartar respuestas que llegan tarde
let agendaActual = null;          // última agenda cargada
let claveAgenda = '';             // "tecnico|fecha" de la agenda cargada
let siguienteMostrada = null;     // id de la visita siguiente cuyo campo se está mostrando

export async function iniciarPlanificacion() {
    if (iniciada) {
        await Promise.all([cargarListado(), refrescarAgenda()]);
        return;
    }
    iniciada = true;

    const formulario = porId('form-visita');
    // El formulario propone hoy; el listado empieza sin filtros (todas las visitas, CU1).
    porId('campo-fecha').value = hoy();

    formulario.addEventListener('input', programarPrevisualizacion);
    formulario.addEventListener('change', programarPrevisualizacion);
    formulario.addEventListener('submit', guardar);
    porId('form-filtros').addEventListener('change', cargarListado);

    try {
        await cargarDesplegables();
    } catch (error) {
        pintarErrores(porId('errores-visita'), error.errores ?? []);
    }
    await cargarListado();
}

// --- Desplegables ---

async function cargarDesplegables() {
    const [{ tecnicos }, { obras }] = await Promise.all([get('/api/tecnicos'), get('/api/obras')]);

    for (const id of ['campo-tecnico', 'filtro-tecnico']) {
        const select = porId(id);
        select.append(...tecnicos.map((t) => crear('option', { texto: t.nombre, atributos: { value: t.id } })));
    }
    porId('campo-obra').append(...obras.map((o) => crear('option', {
        texto: `${o.nombre} (${o.municipio})`,
        atributos: { value: o.id },
    })));
}

// --- Formulario y previsualización ---

function entero(valor) {
    return valor === '' ? null : Number(valor);
}

function datosFormulario() {
    const datos = {
        tecnico_id: entero(porId('campo-tecnico').value),
        obra_id: entero(porId('campo-obra').value),
        fecha: porId('campo-fecha').value,
        hora_inicio: porId('campo-hora').value,
        duracion_min: entero(porId('campo-duracion').value),
        desplazamiento_min: entero(porId('campo-desplazamiento').value),
    };
    // Solo se envía si el servidor ha pedido el campo; si no, la siguiente conserva el suyo.
    if (!porId('grupo-siguiente').hidden) {
        datos.desplazamiento_siguiente_min = entero(porId('campo-siguiente').value);
    }
    return datos;
}

function programarPrevisualizacion() {
    porId('mensaje-visita').hidden = true;
    clearTimeout(temporizador);
    temporizador = setTimeout(previsualizar, ESPERA_PREVISUALIZACION_MS);
}

async function previsualizar() {
    const peticion = ++numeroPeticion;
    const datos = datosFormulario();

    if (datos.tecnico_id === null || datos.fecha === '') {
        agendaActual = null;
        claveAgenda = '';
        porId('agenda-planificacion').replaceChildren(crear('p', { clase: 'ayuda', texto: 'Elige técnico y fecha para ver su agenda.' }));
        mostrarOrigen('Elige técnico, fecha y hora para ver desde dónde sale.');
        return;
    }

    try {
        const clave = `${datos.tecnico_id}|${datos.fecha}`;
        if (clave !== claveAgenda) {
            const agenda = await get('/api/agenda', { tecnico_id: datos.tecnico_id, fecha: datos.fecha });
            if (peticion !== numeroPeticion) {
                return;
            }
            agendaActual = agenda;
            claveAgenda = clave;
        }

        if (datos.hora_inicio === '') {
            dibujarAgenda(porId('agenda-planificacion'), agendaActual);
            mostrarOrigen('Elige la hora para ver desde dónde sale.');
            return;
        }

        const resultado = await post('/api/visitas/validar', datos);
        if (peticion !== numeroPeticion) {
            return;
        }
        mostrarResultado(datos, resultado);
    } catch (error) {
        if (peticion === numeroPeticion) {
            pintarErrores(porId('errores-visita'), error.errores ?? []);
        }
    }
}

function mostrarResultado(datos, resultado) {
    const formulario = porId('form-visita');
    repartirErrores(formulario, porId('errores-visita'), resultado.errores);
    mostrarOrigen(resultado.origen ? resultado.origen.texto : 'Elige la hora para ver desde dónde sale.');
    mostrarSiguiente(resultado.siguiente);

    // La franja se dibuja con los datos del formulario; si cabe o no, lo ha dicho el servidor.
    let propuesta = null;
    if (datos.duracion_min !== null && datos.desplazamiento_min !== null && agendaActual) {
        const inicio = aMinutos(datos.hora_inicio);
        propuesta = {
            inicio: datos.hora_inicio,
            fin: aHora(Math.min(inicio + datos.duracion_min, 24 * 60 - 1)),
            salida: aHora(Math.max(inicio - datos.desplazamiento_min, 0)),
            cabe: resultado.valida,
        };
    }
    if (agendaActual) {
        dibujarAgenda(porId('agenda-planificacion'), agendaActual, propuesta);
    }
}

function mostrarOrigen(texto) {
    porId('texto-origen').textContent = texto;
}

/** Campo "Desplazamiento hasta la siguiente": solo si el servidor lo pide (otra obra, CU2 paso 5). */
function mostrarSiguiente(siguiente) {
    const grupo = porId('grupo-siguiente');
    if (!siguiente || !siguiente.pedir_desplazamiento) {
        grupo.hidden = true;
        siguienteMostrada = null;
        return;
    }
    porId('etiqueta-siguiente').textContent =
        `Desplazamiento hasta la siguiente visita (${siguiente.obra}, ${siguiente.inicio}) en min`;
    // Se precarga con el valor que la siguiente ya tenía, solo la primera vez que aparece.
    if (siguienteMostrada !== siguiente.id) {
        porId('campo-siguiente').value = String(siguiente.desplazamiento_min);
        siguienteMostrada = siguiente.id;
    }
    grupo.hidden = false;
}

async function guardar(evento) {
    evento.preventDefault();
    clearTimeout(temporizador);
    const formulario = porId('form-visita');
    const mensaje = porId('mensaje-visita');
    mensaje.hidden = true;
    if (!formulario.reportValidity()) {
        return;
    }

    const boton = formulario.querySelector('button[type="submit"]');
    boton.disabled = true;
    try {
        await post('/api/visitas', datosFormulario());
        repartirErrores(formulario, porId('errores-visita'), []);
        mensaje.textContent = 'Visita guardada.';
        mensaje.hidden = false;
        porId('campo-hora').value = '';
        porId('grupo-siguiente').hidden = true;
        siguienteMostrada = null;
        await Promise.all([refrescarAgenda(), cargarListado()]);
    } catch (error) {
        repartirErrores(formulario, porId('errores-visita'), error.errores ?? []);
    } finally {
        boton.disabled = false;
    }
}

/** Vuelve a pedir la agenda del técnico y fecha del formulario (tras guardar o cancelar). */
async function refrescarAgenda() {
    claveAgenda = '';
    await previsualizar();
}

// --- Listado ---

async function cargarListado() {
    const errores = porId('errores-listado');
    const cuerpo = porId('cuerpo-listado');
    try {
        const { visitas } = await get('/api/visitas', {
            tecnico_id: porId('filtro-tecnico').value,
            fecha: porId('filtro-fecha').value,
            estado: porId('filtro-estado').value,
        });
        pintarErrores(errores, []);
        cuerpo.replaceChildren(...visitas.map(filaDeVisita));
        if (visitas.length === 0) {
            cuerpo.append(crear('tr', {}, [crear('td', { clase: 'vacio', texto: 'No hay visitas con estos filtros.', atributos: { colspan: 7 } })]));
        }
    } catch (error) {
        pintarErrores(errores, error.errores ?? []);
    }
}

function filaDeVisita(visita) {
    const celda = (etiqueta, ...contenido) => crear('td', { atributos: { 'data-etiqueta': etiqueta } }, contenido);

    return crear('tr', {}, [
        celda('Fecha', fechaLegible(visita.fecha)),
        celda('Hora', `${visita.inicio}–${visita.fin}`),
        celda('Técnico', visita.tecnico),
        celda('Obra', `${visita.obra} (${visita.municipio})`),
        celda(
            'Desplazamiento',
            `${visita.desplazamiento_min} min`,
            visita.revisar_desplazamiento ? crear('span', { clase: 'etiqueta-revisar', texto: 'Revisar desplazamiento' }) : null
        ),
        celda('Estado', crear('span', { clase: `estado estado-${visita.estado.toLowerCase()}`, texto: visita.estado })),
        celda('Acción', accionDeVisita(visita)),
    ]);
}

function accionDeVisita(visita) {
    if (visita.estado === 'Programada') {
        const boton = crear('button', { clase: 'boton boton-peligro boton-pequeno', texto: 'Cancelar', atributos: { type: 'button' } });
        boton.addEventListener('click', () => cancelar(visita));
        return boton;
    }

    const completada = visita.estado === 'Completada';
    const texto = completada ? visita.comentario_cierre : visita.motivo_cancelacion;
    const autor = completada ? visita.completada_por : visita.cancelada_por;
    const momento = completada ? visita.completada_en : visita.cancelada_en;
    return crear('details', {}, [
        crear('summary', { texto: completada ? 'Ver comentario' : 'Ver motivo' }),
        crear('p', { texto: texto ?? '' }),
        crear('p', { clase: 'ayuda', texto: autor ? `${autor}${momento ? `, ${momento}` : ''}` : 'Carga inicial de datos' }),
    ]);
}

async function cancelar(visita) {
    const hecho = await pedirTexto({
        titulo: 'Cancelar visita',
        descripcion: `${fechaLegible(visita.fecha)}, ${visita.inicio}–${visita.fin} · ${visita.tecnico} · ${visita.obra}`,
        etiqueta: 'Motivo de la cancelación (mínimo 5 caracteres)',
        maximo: 2000,
        confirmar: 'Cancelar visita',
        enviar: (motivo) => post(`/api/visitas/${visita.id}/cancelar`, { motivo }),
    });
    if (hecho) {
        await Promise.all([cargarListado(), refrescarAgenda()]);
    }
}
