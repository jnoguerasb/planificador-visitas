// Formulario de inicio de sesión. El servidor responde siempre "Usuario o contraseña incorrectos"
// ante cualquier fallo; aquí solo se muestra lo que dice.

import { post } from './api.js';
import { porId } from './dom.js';

/** alEntrar recibe la respuesta del login: { usuario, csrf }. */
export function iniciarLogin(alEntrar) {
    const formulario = porId('form-login');
    const error = porId('login-error');

    formulario.addEventListener('submit', async (evento) => {
        evento.preventDefault();
        error.hidden = true;
        const boton = formulario.querySelector('button[type="submit"]');
        boton.disabled = true;
        try {
            const respuesta = await post('/api/login', {
                email: porId('login-email').value,
                password: porId('login-clave').value,
            });
            formulario.reset();
            alEntrar(respuesta);
        } catch (fallo) {
            error.textContent = fallo.errores?.[0]?.mensaje ?? 'Ha ocurrido un error. Inténtalo de nuevo.';
            error.hidden = false;
        } finally {
            boton.disabled = false;
        }
    });
}

export function mostrarLogin(mensaje = '') {
    const error = porId('login-error');
    error.textContent = mensaje;
    error.hidden = mensaje === '';
    porId('vista-login').hidden = false;
    porId('login-email').focus();
}
