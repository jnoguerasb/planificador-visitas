-- Datos de ejemplo. Fechas relativas a CURRENT_DATE para que la demo siempre tenga visitas cercanas.
-- Sin usuarios: los crea bin/crear_usuarios_demo.php con DEMO_PASSWORD, para no guardar contraseñas aquí.
-- Se ejecuta con el usuario propietario después de 001_esquema.sql.
--
-- Todas las visitas cumplen las reglas de negocio. Casos límite incluidos:
--   visita 2:  llega con 30 min justos desde la visita 1
--   visita 5:  acaba a las 17:00 justas
--   visita 6:  sale de la sede a las 08:00 justas
--   visita 9:  su anterior (8) se canceló: origen reasignado a la 7 y "revisar desplazamiento"
--   visita 11: desplazamiento máximo (240 min)
--   visita 12: desplazamiento de 60 desde la sede, salida a las 08:00 justas

SET client_encoding = 'UTF8';

INSERT INTO sede (id, nombre, direccion, municipio) VALUES
    (1, 'Sede central', 'Carrer de la Indústria, 25', 'Sabadell');

INSERT INTO tecnicos (id, nombre, activo) VALUES
    (1, 'Ana Puig',     TRUE),
    (2, 'Bernat Soler', TRUE),
    (3, 'Carla Vidal',  TRUE),
    (4, 'David Roca',   TRUE);

INSERT INTO obras (id, nombre, direccion, municipio) VALUES
    (1, 'Escuela Can Puiggener',       'Carrer de Josep Aparici, 12', 'Sabadell'),
    (2, 'Viviendas Rambla',            'Rambla, 140',                 'Sabadell'),
    (3, 'Nave logística Can Roqueta',  'Carrer de Viladecavalls, 8',  'Terrassa'),
    (4, 'Rehabilitación fachada Ègara','Carrer de Sant Pere, 31',     'Terrassa'),
    (5, 'Centro deportivo Mirasol',    'Avinguda de Can Graells, 4',  'Sant Cugat del Vallès'),
    (6, 'Ampliación ambulatorio',      'Carrer de Sant Jaume, 55',    'Rubí');

INSERT INTO visitas (id, tecnico_id, obra_id, fecha, hora_inicio, duracion_min, desplazamiento_min,
                     origen_tipo, origen_visita_id, revisar_desplazamiento, estado,
                     comentario_cierre, completada_en, motivo_cancelacion, cancelada_en) VALUES
    -- Ana, ayer
    (1,  1, 1, CURRENT_DATE - 1, '08:30',  60, 30, 'SEDE',   NULL, FALSE, 'Completada',
         'Revisión de la estructura del gimnasio. Sin incidencias.', (CURRENT_DATE - 1) + TIME '09:40', NULL, NULL),
    (2,  1, 3, CURRENT_DATE - 1, '10:00',  90, 30, 'VISITA', 1,    FALSE, 'Programada', NULL, NULL, NULL, NULL),
    -- Ana, hoy (la de 10:00 a 11:00 en la Escuela es la partida común de los casos de prueba)
    (3,  1, 1, CURRENT_DATE,     '10:00',  60, 30, 'SEDE',   NULL, FALSE, 'Programada', NULL, NULL, NULL, NULL),
    (4,  1, 2, CURRENT_DATE,     '11:30',  60, 30, 'VISITA', 3,    FALSE, 'Programada', NULL, NULL, NULL, NULL),
    (5,  1, 4, CURRENT_DATE,     '13:00', 240, 30, 'VISITA', 4,    FALSE, 'Programada', NULL, NULL, NULL, NULL),
    -- Bernat, hoy
    (6,  2, 5, CURRENT_DATE,     '08:30',  90, 30, 'SEDE',   NULL, FALSE, 'Programada', NULL, NULL, NULL, NULL),
    (7,  2, 6, CURRENT_DATE,     '11:00', 120, 60, 'VISITA', 6,    FALSE, 'Programada', NULL, NULL, NULL, NULL),
    (8,  2, 5, CURRENT_DATE,     '14:00',  60, 30, 'VISITA', 7,    FALSE, 'Cancelada',  NULL, NULL,
         'La obra está cerrada por inspección municipal.', CURRENT_TIMESTAMP),
    (9,  2, 2, CURRENT_DATE,     '15:45',  75, 45, 'VISITA', 7,    TRUE,  'Programada', NULL, NULL, NULL, NULL),
    -- Carla, mañana
    (10, 3, 3, CURRENT_DATE + 1, '08:30', 120, 30, 'SEDE',   NULL, FALSE, 'Programada', NULL, NULL, NULL, NULL),
    (11, 3, 4, CURRENT_DATE + 1, '14:30', 120, 240, 'VISITA', 10,  FALSE, 'Programada', NULL, NULL, NULL, NULL),
    -- David, pasado mañana
    (12, 4, 6, CURRENT_DATE + 2, '09:00',  60, 60, 'SEDE',   NULL, FALSE, 'Programada', NULL, NULL, NULL, NULL);

-- Los id se han puesto a mano: las secuencias siguen a partir del último.
SELECT setval(pg_get_serial_sequence('sede', 'id'),     (SELECT MAX(id) FROM sede));
SELECT setval(pg_get_serial_sequence('tecnicos', 'id'), (SELECT MAX(id) FROM tecnicos));
SELECT setval(pg_get_serial_sequence('obras', 'id'),    (SELECT MAX(id) FROM obras));
SELECT setval(pg_get_serial_sequence('visitas', 'id'),  (SELECT MAX(id) FROM visitas));
