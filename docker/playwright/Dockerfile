FROM node:20-alpine@sha256:16858294071a56ffd4cce9f17b57136cc39e41507b40e245b4f8e906f7a19463

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
