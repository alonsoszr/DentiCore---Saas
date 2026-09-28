# Imagen denticore-spa (SDD §1.11): archivos estáticos servidos por nginx con la CSP de §1.7.

FROM node:22-alpine AS build
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
# Origen de la API (sin /api/v1), fijado al construir la imagen.
ARG VITE_API_URL
ENV VITE_API_URL=$VITE_API_URL
RUN npm run build && npm run check:size

FROM nginx:1.27-alpine
# Orígenes permitidos por la CSP; se sustituyen al iniciar el contenedor.
ENV API_ORIGIN=http://localhost:8000 \
    BUCKET_ORIGIN=http://localhost:9000
COPY docker/default.conf.template /etc/nginx/templates/default.conf.template
COPY --from=build /app/dist /usr/share/nginx/html
EXPOSE 8080
