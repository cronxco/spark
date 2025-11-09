FROM node:20-alpine@sha256:6178e78b972f79c335df281f4b7674a2d85071aae2af020ffa39f0a770265435

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
