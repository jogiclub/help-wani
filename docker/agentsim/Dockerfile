# 파일 위치: docker/agentsim/Dockerfile
# 역할: 고객 PC 에이전트와 상담원 뷰어를 흉내 내는 검증용 컨테이너
FROM python:3.12-slim

RUN pip install --no-cache-dir websockets==13.1 pillow==11.0.0

COPY docker/agentsim/agent_sim.py /usr/local/bin/agent_sim.py
COPY scripts/viewer-probe.py /usr/local/bin/viewer-probe.py

RUN chmod +x /usr/local/bin/agent_sim.py /usr/local/bin/viewer-probe.py

CMD ["python3", "/usr/local/bin/agent_sim.py"]
