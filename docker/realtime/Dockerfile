# 파일 위치: docker/realtime/Dockerfile
# 역할: 상담 실시간 메시지 서버(Node.js) 컨테이너
#
# TLS 는 앞단(nginx)이 맡는다. 이 컨테이너는 평문 웹소켓만 받는다.
FROM node:20-bookworm-slim

WORKDIR /app

# 의존성 먼저 설치해 레이어 캐시를 활용한다.
COPY realtime/package.json realtime/package-lock.json* ./
RUN npm install --omit=dev --no-audit --no-fund

COPY realtime/src ./src

ENV NODE_ENV=production
EXPOSE 8091

USER node

CMD ["node", "src/server.js"]
