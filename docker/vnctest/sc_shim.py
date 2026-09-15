#!/usr/bin/env python3
"""
파일 위치: docker/vnctest/sc_shim.py
역할: 고객 런처의 역방향 접속 동작(TLS 터널 + UltraVNC Repeater ID 전송)을 모의하여
      리피터/websockify/noVNC 경로를 서버 없이 검증한다.

동작 순서
  1. relay_host:relay_port 로 TLS 연결 (런처의 SslStream 구간에 해당)
  2. "ID:<repeater_id>" 를 NUL 로 250 바이트까지 채워 전송 (winvnc -id: 동작과 동일)
  3. 로컬 VNC 서버(127.0.0.1:5901)에 연결하고 양방향 바이트 중계
"""
import argparse
import socket
import ssl
import sys
import threading
import time

ID_FIELD_LEN = 250


def build_id_field(repeater_id: str) -> bytes:
    raw = ("ID:" + str(repeater_id)).encode("ascii")
    if len(raw) > ID_FIELD_LEN:
        raise ValueError("repeater id too long")
    return raw + b"\x00" * (ID_FIELD_LEN - len(raw))


def connect_relay(host, port, insecure):
    ctx = ssl.create_default_context()
    if insecure:
        # 검증용 자체 서명 인증서에 한해 사용한다. 실제 런처는 검증을 우회하지 않는다.
        ctx.check_hostname = False
        ctx.verify_mode = ssl.CERT_NONE
    raw = socket.create_connection((host, port), timeout=10)
    return ctx.wrap_socket(raw, server_hostname=host)


def relay_loop(src, dst, label, stop):
    """한 방향 복사. 스레드 하나가 한 방향만 담당한다."""
    first = True
    try:
        while not stop.is_set():
            data = src.recv(65536)
            if not data:
                break
            if first:
                first = False
                print("[shim] %s 첫 데이터 %d바이트: %r" % (label, len(data), data[:24]), flush=True)
            dst.sendall(data)
    except OSError:
        pass
    finally:
        stop.set()
        for s in (src, dst):
            try:
                s.shutdown(socket.SHUT_RDWR)
            except OSError:
                pass


def pump(relay, vnc):
    """
    양방향 중계.

    주의: select() + 타임아웃 소켓 조합은 TLS 1.3 에서 오동작한다.
    서버가 핸드셰이크 직후 NewSessionTicket 레코드를 보내면 소켓은 읽기 가능해지지만
    애플리케이션 데이터는 없어, 타임아웃이 걸린 recv() 가 그대로 블록되다가
    socket.timeout 으로 연결이 끊긴 것처럼 보인다.
    따라서 소켓을 블로킹 모드로 되돌리고 방향별 스레드로 복사한다.
    """
    relay.settimeout(None)
    vnc.settimeout(None)

    stop = threading.Event()
    threads = [
        threading.Thread(target=relay_loop, args=(relay, vnc, "relay->vnc", stop), daemon=True),
        threading.Thread(target=relay_loop, args=(vnc, relay, "vnc->relay", stop), daemon=True),
    ]
    for t in threads:
        t.start()
    for t in threads:
        t.join()


def main():
    p = argparse.ArgumentParser()
    p.add_argument("--relay-host", required=True)
    p.add_argument("--relay-port", type=int, default=5501)
    p.add_argument("--repeater-id", required=True)
    p.add_argument("--vnc-host", default="127.0.0.1")
    p.add_argument("--vnc-port", type=int, default=5901)
    p.add_argument("--insecure", action="store_true")
    p.add_argument("--retry", type=int, default=30)
    args = p.parse_args()

    for attempt in range(1, args.retry + 1):
        try:
            print(f"[shim] relay 접속 시도 {attempt}: {args.relay_host}:{args.relay_port}", flush=True)
            relay = connect_relay(args.relay_host, args.relay_port, args.insecure)
        except OSError as e:
            print(f"[shim] relay 접속 실패: {e}", flush=True)
            time.sleep(2)
            continue

        print(f"[shim] TLS 연결됨 ({relay.version()}), ID:{args.repeater_id} 전송", flush=True)
        relay.sendall(build_id_field(args.repeater_id))

        try:
            vnc = socket.create_connection((args.vnc_host, args.vnc_port), timeout=10)
        except OSError as e:
            print(f"[shim] 로컬 VNC 접속 실패: {e}", flush=True)
            relay.close()
            time.sleep(2)
            continue

        print("[shim] 중계 시작 (상담원 접속 대기)", flush=True)
        pump(relay, vnc)
        print("[shim] 세션 종료", flush=True)
        relay.close()
        vnc.close()
        return 0

    print("[shim] 재시도 횟수를 초과했습니다", flush=True)
    return 1


if __name__ == "__main__":
    sys.exit(main())
