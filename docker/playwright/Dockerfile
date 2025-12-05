FROM node:20-alpine@sha256:643e7036aa985317ebfee460005e322aa550c6b6883000d01daefb58689a58e2

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
