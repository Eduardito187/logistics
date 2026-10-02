-- ============================================================
-- Init de PostgreSQL — logistics (solo corre con el volumen vacío)
-- ============================================================
-- PostGIS en la base principal y una base aparte para la suite de tests,
-- así los tests nunca pisan los datos de desarrollo.

CREATE EXTENSION IF NOT EXISTS postgis;

CREATE DATABASE logistics_test OWNER logistics;

\connect logistics_test
CREATE EXTENSION IF NOT EXISTS postgis;
