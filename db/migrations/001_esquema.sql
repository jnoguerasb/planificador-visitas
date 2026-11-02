-- Esquema completo del planificador de visitas (análisis, sección 5).
--
-- Se ejecuta con el usuario propietario (planificador_admin), nunca con app_planificador.
-- Requisito: los roles planificador_admin y app_planificador ya existen
-- (en local los crea scripts/crear_bases.ps1; en Neon, a mano siguiendo el README).
-- Este archivo es SQL plano, sin comandos de psql, para poder ejecutarlo también desde PHP.
-- Las restricciones llevan nombre (ck_...) para identificar en los errores cuál ha fallado.

CREATE TABLE sede (
    id        SERIAL PRIMARY KEY,
    nombre    VARCHAR(150) NOT NULL,
    direccion VARCHAR(200) NOT NULL,
    municipio VARCHAR(100) NOT NULL
);

CREATE TABLE tecnicos (
    id     SERIAL PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    activo BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE obras (
    id        SERIAL PRIMARY KEY,
    nombre    VARCHAR(150) NOT NULL,
    direccion VARCHAR(200) NOT NULL,
    municipio VARCHAR(100) NOT NULL
);

CREATE TABLE usuarios (
    id            SERIAL PRIMARY KEY,
    email         VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    rol           VARCHAR(12)  NOT NULL,
    tecnico_id    INT NULL REFERENCES tecnicos (id),
    activo        BOOLEAN NOT NULL DEFAULT TRUE,
    CONSTRAINT ck_usuarios_rol CHECK (rol IN ('PLANIFICADOR', 'TECNICO')),
    -- Un técnico tiene técnico asociado; un planificador, no.
    CONSTRAINT ck_usuarios_rol_tecnico CHECK ((rol = 'TECNICO') = (tecnico_id IS NOT NULL))
);

CREATE TABLE visitas (
    id                     SERIAL PRIMARY KEY,
    tecnico_id             INT  NOT NULL REFERENCES tecnicos (id),
    obra_id                INT  NOT NULL REFERENCES obras (id),
    fecha                  DATE NOT NULL,
    hora_inicio            TIME NOT NULL,
    duracion_min           INT  NOT NULL,
    desplazamiento_min     INT  NOT NULL DEFAULT 30,
    origen_tipo            VARCHAR(6) NOT NULL,
    origen_visita_id       INT NULL REFERENCES visitas (id),
    revisar_desplazamiento BOOLEAN NOT NULL DEFAULT FALSE,
    estado                 VARCHAR(12) NOT NULL DEFAULT 'Programada',
    comentario_cierre      TEXT NULL,
    motivo_cancelacion     TEXT NULL,
    creada_por             INT NULL REFERENCES usuarios (id),  -- NULL = carga inicial (seed)
    creada_en              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completada_por         INT NULL REFERENCES usuarios (id),
    completada_en          TIMESTAMP NULL,
    cancelada_por          INT NULL REFERENCES usuarios (id),
    cancelada_en           TIMESTAMP NULL,
    CONSTRAINT ck_visitas_duracion       CHECK (duracion_min BETWEEN 15 AND 540),
    -- 0–240: el mínimo de 30 solo se exige entre obras diferentes (desde la sede o en la misma obra es 0;
    -- R1 y R2 del análisis). El mínimo de cada caso lo aplica ValidadorVisita.
    CONSTRAINT ck_visitas_desplazamiento CHECK (desplazamiento_min BETWEEN 0 AND 240),
    CONSTRAINT ck_visitas_origen_tipo    CHECK (origen_tipo IN ('SEDE', 'VISITA')),
    CONSTRAINT ck_visitas_estado         CHECK (estado IN ('Programada', 'Completada', 'Cancelada')),
    CONSTRAINT ck_visitas_hora_inicio    CHECK (hora_inicio >= TIME '08:00'),
    -- Fin de jornada en minutos: con TIME + INTERVAL, 16:00 + 540 min daría 01:00 y pasaría.
    CONSTRAINT ck_visitas_fin_jornada    CHECK (EXTRACT(EPOCH FROM hora_inicio) / 60 + duracion_min <= 1020),
    -- Solo la coherencia tipo/visita; un tipo desconocido lo rechaza ck_visitas_origen_tipo.
    CONSTRAINT ck_visitas_origen         CHECK (origen_tipo NOT IN ('SEDE', 'VISITA')
                                                OR (origen_tipo = 'SEDE') = (origen_visita_id IS NULL)),
    CONSTRAINT ck_visitas_completada_comentario CHECK (estado <> 'Completada' OR comentario_cierre IS NOT NULL),
    CONSTRAINT ck_visitas_cancelada_motivo      CHECK (estado <> 'Cancelada'  OR motivo_cancelacion IS NOT NULL)
    -- "Salir de la sede no antes de las 08:00" no va aquí: haría fallar cancelaciones legítimas.
    -- Vive solo en ValidadorVisita (análisis, sección 5).
);

CREATE INDEX idx_visitas_tecnico_fecha ON visitas (tecnico_id, fecha);
CREATE INDEX idx_visitas_fecha_hora    ON visitas (fecha, hora_inicio);

-- Permisos del usuario de la aplicación: solo leer, insertar y actualizar.
-- Sin DELETE (nada se borra) ni permisos para crear o cambiar objetos del esquema.
GRANT USAGE ON SCHEMA public TO app_planificador;
GRANT SELECT, INSERT, UPDATE ON sede, tecnicos, obras, usuarios, visitas TO app_planificador;
GRANT USAGE ON ALL SEQUENCES IN SCHEMA public TO app_planificador;
