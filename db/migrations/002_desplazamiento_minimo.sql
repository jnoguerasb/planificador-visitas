-- R1 y R2 resueltos según el enunciado (02/11/2026): el mínimo de 30 min solo se exige entre
-- visitas a obras diferentes. Desde la sede o en la misma obra el desplazamiento puede ser 0.
-- La base admite de 0 a 240; el mínimo que corresponde a cada caso lo aplica ValidadorVisita.
--
-- Se ejecuta con el usuario propietario (planificador_admin), después de 001_esquema.sql.

ALTER TABLE visitas DROP CONSTRAINT ck_visitas_desplazamiento;
ALTER TABLE visitas ADD CONSTRAINT ck_visitas_desplazamiento CHECK (desplazamiento_min BETWEEN 0 AND 240);
