# ADR-020 Phase 1 — standard Next.js standalone multi-stage build.
# Requires next.config.ts's output: "standalone" (added alongside this file).
# No `public/` copy step — this app has no public/ directory today; add one
# back if static assets are ever added there.

FROM node:22-alpine AS deps
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci

FROM node:22-alpine AS builder
WORKDIR /app
# NEXT_PUBLIC_* vars are inlined into the client bundle at build time, not
# read at container-start time — must be passed as a build arg (see
# docker-compose.prod.yml's `build: args:`), an env_file at runtime is not
# enough for this one variable.
ARG NEXT_PUBLIC_API_URL
ENV NEXT_PUBLIC_API_URL=$NEXT_PUBLIC_API_URL
COPY --from=deps /app/node_modules ./node_modules
COPY . .
RUN npm run build

FROM node:22-alpine AS runner
WORKDIR /app
ENV NODE_ENV=production
COPY --from=builder /app/.next/standalone ./
COPY --from=builder /app/.next/static ./.next/static

EXPOSE 3000
CMD ["node", "server.js"]
