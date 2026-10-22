# 파일 위치: docker/api/Dockerfile
# 역할: FastAPI 포털 컨테이너 (앞단 Apache(web)가 요청을 넘긴다, docs/fastapi-migration-plan.md)
FROM python:3.12.14-slim-bookworm

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1 \
    TZ=UTC

WORKDIR /app

# 의존성 먼저 설치해 레이어 캐시를 활용한다.
COPY backend/requirements.txt ./requirements.txt
RUN pip install -r requirements.txt

COPY backend/app ./app

# 업로드 폴더(backend/assets/uploads)를 앞단 Apache 와 같은 사용자(www-data, uid 33)로 쓴다. assets 는 docker-compose 에서 /srv/assets 로 붙인다.
USER www-data

EXPOSE 8000

# 고객 IP 는 X-Forwarded-For 로 받지 않는다(CLAUDE.md 3.12). uvicorn 은 기본으로 127.0.0.1 의 프록시 헤더를 믿으므로 끈다.
CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8000", "--no-proxy-headers", "--no-server-header"]
