# Multi-stage Next.js build.
# Build context is the monorepo root (compose sets context: ..) so paths
# below are relative to baja-sae-brasil-online/.

# Stage 1: install full deps and build the app.
FROM node:20-alpine AS builder
WORKDIR /app
# HUSKY=0 disables husky's prepare-script hook install, which fails inside
# a container with no .git dir. Juan's package.json declares "prepare": "husky".
ENV HUSKY=0
COPY baja-js/package.json baja-js/package-lock.json ./
RUN npm ci
COPY baja-js/ ./
RUN npm run build

# Stage 2: production runtime — copies build output and installs prod deps.
FROM node:20-alpine AS runner
WORKDIR /app
ENV NODE_ENV=production
ENV HUSKY=0
COPY --from=builder /app/.next ./.next
COPY --from=builder /app/public ./public
COPY --from=builder /app/package.json ./package.json
COPY --from=builder /app/package-lock.json ./package-lock.json
COPY --from=builder /app/next.config.js ./next.config.js
# --ignore-scripts skips the package.json "prepare": "husky" hook, which
# would otherwise invoke husky here — and husky is a devDep that --omit=dev
# strips. The builder stage already ran scripts with husky present.
RUN npm ci --omit=dev --ignore-scripts
EXPOSE 3000
CMD ["npm", "start"]
