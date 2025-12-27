FROM pgvector/pgvector:0.8.1-pg17@sha256:1a5ea285a159bed991c74a9c4751b6eadc536b3c3263f3f95ec3865885a62b69

# Install PostGIS
RUN apt-get update && \
    apt-get install -y --no-install-recommends \
        postgresql-17-postgis-3 \
        postgresql-17-postgis-3-scripts && \
    rm -rf /var/lib/apt/lists/*

# Copy initialization script
COPY ./init-extensions.sql /docker-entrypoint-initdb.d/
