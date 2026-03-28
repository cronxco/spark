FROM pgvector/pgvector:0.8.1-pg17@sha256:3e8b3adfd27b5707128f60956f62a793c3c9326ea8cfaf0eab7adccb5d700b21

# Install PostGIS
RUN apt-get update && \
    apt-get install -y --no-install-recommends \
        postgresql-17-postgis-3 \
        postgresql-17-postgis-3-scripts && \
    rm -rf /var/lib/apt/lists/*

# Copy initialization script
COPY ./init-extensions.sql /docker-entrypoint-initdb.d/
