FROM pgvector/pgvector:0.8.1-pg17@sha256:1a20228d87a48db9587caebaecfdff065268ebe248dafa5f8b447ce3dc96e594

# Install PostGIS
RUN apt-get update && \
    apt-get install -y --no-install-recommends \
        postgresql-17-postgis-3 \
        postgresql-17-postgis-3-scripts && \
    rm -rf /var/lib/apt/lists/*

# Copy initialization script
COPY ./init-extensions.sql /docker-entrypoint-initdb.d/
