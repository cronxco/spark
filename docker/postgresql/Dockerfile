FROM pgvector/pgvector:0.8.4-pg17@sha256:dd467f03ca5c5581222490e5217e48a262864ccb659be559f8491bbafdc97da0

# Install PostGIS
RUN apt-get update && \
    apt-get install -y --no-install-recommends \
        postgresql-17-postgis-3 \
        postgresql-17-postgis-3-scripts && \
    rm -rf /var/lib/apt/lists/*

# Copy initialization script
COPY ./init-extensions.sql /docker-entrypoint-initdb.d/
