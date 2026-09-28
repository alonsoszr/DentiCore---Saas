#!/usr/bin/env bash
# Inicialización de PostgreSQL 16 (TASK-001; SDD §2.1, §2.13 y DI-10).
#
# Crea los tres roles de BD, las bases `denticore` y `denticore_testing` (propiedad del
# migrador) y las extensiones requeridas. Lo ejecuta docker-entrypoint-initdb.d al crear
# el volumen y también la CI contra el servicio de PostgreSQL (PGHOST/PGPASSWORD).
#
#   denticore_migrator  propietario del esquema; ejecuta las migraciones.
#   denticore_app       API y workers en contexto de clínica; sin BYPASSRLS.
#   denticore_platform  servicios de plataforma y retención; BYPASSRLS.
set -euo pipefail

: "${POSTGRES_USER:=postgres}"
: "${DENTICORE_MIGRATOR_PASSWORD:?falta DENTICORE_MIGRATOR_PASSWORD}"
: "${DENTICORE_APP_PASSWORD:?falta DENTICORE_APP_PASSWORD}"
: "${DENTICORE_PLATFORM_PASSWORD:?falta DENTICORE_PLATFORM_PASSWORD}"

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname postgres \
    -v migrator_pw="$DENTICORE_MIGRATOR_PASSWORD" \
    -v app_pw="$DENTICORE_APP_PASSWORD" \
    -v platform_pw="$DENTICORE_PLATFORM_PASSWORD" <<'SQL'
SELECT format('CREATE ROLE denticore_migrator LOGIN PASSWORD %L', :'migrator_pw')
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'denticore_migrator') \gexec
SELECT format('CREATE ROLE denticore_app LOGIN NOBYPASSRLS PASSWORD %L', :'app_pw')
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'denticore_app') \gexec
SELECT format('CREATE ROLE denticore_platform LOGIN BYPASSRLS PASSWORD %L', :'platform_pw')
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'denticore_platform') \gexec

SELECT 'CREATE DATABASE denticore OWNER denticore_migrator'
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = 'denticore') \gexec
SELECT 'CREATE DATABASE denticore_testing OWNER denticore_migrator'
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = 'denticore_testing') \gexec
SQL

for db in denticore denticore_testing; do
    psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$db" <<'SQL'
CREATE EXTENSION IF NOT EXISTS btree_gist;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS pgcrypto;

REVOKE ALL ON SCHEMA public FROM PUBLIC;
ALTER SCHEMA public OWNER TO denticore_migrator;
GRANT USAGE ON SCHEMA public TO denticore_app, denticore_platform;

-- Las tablas y secuencias que cree el migrador quedan accesibles para la API y la
-- plataforma; la RLS (TASK-007) restringe después las filas por clínica.
ALTER DEFAULT PRIVILEGES FOR ROLE denticore_migrator IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO denticore_app, denticore_platform;
ALTER DEFAULT PRIVILEGES FOR ROLE denticore_migrator IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO denticore_app, denticore_platform;
ALTER DEFAULT PRIVILEGES FOR ROLE denticore_migrator IN SCHEMA public
    GRANT EXECUTE ON FUNCTIONS TO denticore_app, denticore_platform;
SQL
done
