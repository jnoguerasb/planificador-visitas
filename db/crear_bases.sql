-- Prepara un PostgreSQL local para el proyecto: los dos usuarios y las bases de desarrollo y de tests.
-- Se ejecuta una sola vez, como superusuario, pasando las contraseñas como variables de psql:
--
--   psql -U postgres -v ON_ERROR_STOP=1 -v clave_admin=... -v clave_app=... -f db/crear_bases.sql
--
-- planificador_admin es el propietario de las bases (migraciones y seed); app_planificador es el de la
-- aplicación y solo recibe SELECT, INSERT y UPDATE (los GRANT están en 001_esquema.sql).
-- Ninguno es superusuario. Las contraseñas no se guardan en ningún archivo del repositorio.

CREATE ROLE planificador_admin LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE PASSWORD :'clave_admin';
CREATE ROLE app_planificador LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE PASSWORD :'clave_app';

CREATE DATABASE planificador OWNER planificador_admin ENCODING 'UTF8' TEMPLATE template0;
CREATE DATABASE planificador_test OWNER planificador_admin ENCODING 'UTF8' TEMPLATE template0;
