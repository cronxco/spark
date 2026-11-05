-- Enable pgvector extension (for vector embeddings)
CREATE EXTENSION IF NOT EXISTS vector;

-- Enable PostGIS extensions (for geospatial data)
CREATE EXTENSION IF NOT EXISTS postgis;
CREATE EXTENSION IF NOT EXISTS postgis_topology;

-- Also enable extensions on testing database
\c testing
CREATE EXTENSION IF NOT EXISTS vector;
CREATE EXTENSION IF NOT EXISTS postgis;
CREATE EXTENSION IF NOT EXISTS postgis_topology;
