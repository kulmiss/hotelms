# Development image for the React app (production build comes in phase 7).
FROM node:22-alpine
WORKDIR /app
EXPOSE 5173
# Installs new packages on every start (quick when nothing changed).
CMD ["sh", "-c", "npm install --no-audit --no-fund && npm run dev"]
