FROM pgvector/pgvector:0.8.2-pg17@sha256:494dff7e67e7bc2c826b94c331364978d145ebb86fd338154138b084223b7f67

# Install PostGIS
RUN apt-get update && \
    apt-get install -y --no-install-recommends \
        postgresql-17-postgis-3 \
        postgresql-17-postgis-3-scripts && \
    rm -rf /var/lib/apt/lists/*

# Copy initialization script
COPY ./init-extensions.sql /docker-entrypoint-initdb.d/
