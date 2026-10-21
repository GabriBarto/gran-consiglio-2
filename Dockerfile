FROM node:22-alpine

ENV NODE_ENV=production \
    PORT=3000

WORKDIR /srv/app

# prima le dipendenze, così la cache di Docker le riusa finché package*.json non cambia
COPY app/package.json app/package-lock.json ./
RUN npm ci --omit=dev

COPY app/ ./

USER node
EXPOSE 3000

HEALTHCHECK --interval=15s --timeout=3s --start-period=20s \
  CMD wget -qO- http://127.0.0.1:3000/health || exit 1

CMD ["node", "server.js"]
