FROM node:24-alpine@sha256:d1b3b4da11eefd5941e7f0b9cf17783fc99d9c6fc34884a665f40a06dbdfc94f

WORKDIR /app

# Install basic utilities
RUN apk add --no-cache wget curl

# Copy package files
COPY package.json package-lock.json* ./

# Install dependencies
RUN npm install

# Copy application code
COPY index.js ./

# Create storage directory
RUN mkdir -p /app/storage

EXPOSE 3000

CMD ["node", "index.js"]
