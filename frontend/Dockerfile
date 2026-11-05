# Development image for the React app (production build comes in phase 7).
FROM node:22-alpine
WORKDIR /app
EXPOSE 5173
CMD ["sh", "-c", "[ -d node_modules/.bin ] || npm install; npm run dev"]
