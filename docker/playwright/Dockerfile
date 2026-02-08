FROM node:20-alpine@sha256:09e2b3d9726018aecf269bd35325f46bf75046a643a66d28360ec71132750ec8

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
