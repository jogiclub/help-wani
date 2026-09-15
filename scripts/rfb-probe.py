#!/usr/bin/env python3
"""
파일 위치: scripts/rfb-probe.py
역할: 뷰어 입장에서 리피터에 접속해 RFB 핸드셰이크와 VNC 인증, 화면 갱신 수신까지 검증한다.
      (브라우저 없이 Phase 0 S-3, S-4 의 프로토콜 구간을 자동 점검하기 위한 도구)

사용 예:
    python3 rfb-probe.py --host 127.0.0.1 --port 5900 --repeater-id 123456789 --password Test1234
"""
import argparse
import base64
import os
import socket
import ssl
import struct
import subprocess
import sys

ID_FIELD_LEN = 250



class WebSocketStream(object):
    """
    websockify 로 연결하기 위한 최소 WebSocket 클라이언트.
    noVNC 가 실제로 쓰는 경로(wss -> websockify -> 리피터)를 그대로 검증하기 위해 사용한다.
    recv/sendall 만 제공하므로 RFB 코드에서는 일반 소켓처럼 쓸 수 있다.
    """

    def __init__(self, host, port, path, token, insecure=True):
        raw = socket.create_connection((host, port), timeout=15)
        ctx = ssl.create_default_context()
        if insecure:
            ctx.check_hostname = False
            ctx.verify_mode = ssl.CERT_NONE
        self.sock = ctx.wrap_socket(raw, server_hostname=host)
        self.buf = b""
        self._handshake(host, port, path, token)

    def _handshake(self, host, port, path, token):
        key = base64.b64encode(os.urandom(16)).decode()
        req = (
            "GET %s?token=%s HTTP/1.1\r\n"
            "Host: %s:%d\r\n"
            "Upgrade: websocket\r\n"
            "Connection: Upgrade\r\n"
            "Sec-WebSocket-Key: %s\r\n"
            "Sec-WebSocket-Version: 13\r\n"
            "Sec-WebSocket-Protocol: binary\r\n"
            "\r\n"
        ) % (path, token, host, port, key)
        self.sock.sendall(req.encode())

        header = b""
        while b"\r\n\r\n" not in header:
            chunk = self.sock.recv(4096)
            if not chunk:
                raise ConnectionError("웹소켓 핸드셰이크 중 연결이 끊겼습니다")
            header += chunk

        head, _, rest = header.partition(b"\r\n\r\n")
        status = head.split(b"\r\n")[0].decode()
        if "101" not in status:
            raise ConnectionError("웹소켓 업그레이드 실패: " + status)
        self.buf_raw = rest

    def _read_raw(self, n):
        while len(self.buf_raw) < n:
            chunk = self.sock.recv(65536)
            if not chunk:
                raise ConnectionError("연결이 끊겼습니다")
            self.buf_raw += chunk
        out, self.buf_raw = self.buf_raw[:n], self.buf_raw[n:]
        return out

    def _read_frame(self):
        b1, b2 = self._read_raw(2)
        opcode = b1 & 0x0F
        length = b2 & 0x7F
        if length == 126:
            length = struct.unpack(">H", self._read_raw(2))[0]
        elif length == 127:
            length = struct.unpack(">Q", self._read_raw(8))[0]
        payload = self._read_raw(length) if length else b""
        if opcode == 0x8:
            raise ConnectionError("서버가 웹소켓 연결을 닫았습니다")
        return opcode, payload

    def recv(self, n):
        while not self.buf:
            opcode, payload = self._read_frame()
            if opcode in (0x1, 0x2):
                self.buf += payload
        out, self.buf = self.buf[:n], self.buf[n:]
        return out

    def sendall(self, data):
        mask = os.urandom(4)
        masked = bytes(b ^ mask[i % 4] for i, b in enumerate(data))
        header = bytes([0x82])
        n = len(data)
        if n < 126:
            header += bytes([0x80 | n])
        elif n < 65536:
            header += bytes([0x80 | 126]) + struct.pack(">H", n)
        else:
            header += bytes([0x80 | 127]) + struct.pack(">Q", n)
        self.sock.sendall(header + mask + masked)

    def close(self):
        self.sock.close()


def recv_exact(sock, n):
    buf = b""
    while len(buf) < n:
        chunk = sock.recv(n - len(buf))
        if not chunk:
            raise ConnectionError("연결이 예기치 않게 끊겼습니다")
        buf += chunk
    return buf


def mirror_bits(b):
    """VNC 인증은 비밀번호 각 바이트의 비트 순서를 뒤집어 DES 키로 사용한다."""
    out = 0
    for i in range(8):
        if b & (1 << i):
            out |= 1 << (7 - i)
    return out


def des_response(password, challenge):
    key = bytes(mirror_bits(c) for c in password.encode("latin-1")[:8].ljust(8, b"\x00"))
    # OpenSSL 3.x 는 DES 를 legacy provider 로 옮겼으므로 명시적으로 불러온다.
    cmd = ["openssl", "enc", "-des-ecb", "-K", key.hex(), "-nopad"]
    proc = subprocess.run(cmd + ["-provider", "legacy", "-provider", "default"],
                          input=challenge, capture_output=True)
    if proc.returncode != 0:
        proc = subprocess.run(cmd, input=challenge, capture_output=True, check=True)
    return proc.stdout


def main():
    p = argparse.ArgumentParser()
    p.add_argument("--host", default="127.0.0.1")
    p.add_argument("--port", type=int, default=5900)
    p.add_argument("--repeater-id", required=True)
    p.add_argument("--password", required=True)
    p.add_argument("--ws-token", help="지정하면 wss://host:port/ws 경로(websockify)로 접속한다")
    p.add_argument("--ws-path", default="/ws")
    args = p.parse_args()

    if args.ws_token:
        print("0. websockify 경로로 접속: wss://%s:%d%s" % (args.host, args.port, args.ws_path))
        sock = WebSocketStream(args.host, args.port, args.ws_path, args.ws_token)
    else:
        sock = socket.create_connection((args.host, args.port), timeout=15)

    version = recv_exact(sock, 12)
    print("1. 리피터 프로토콜 버전:", version.decode("ascii").strip())
    if not version.startswith(b"RFB 000.000"):
        print("   경고: 리피터가 아닌 것으로 보입니다")

    id_field = ("ID:" + args.repeater_id).encode("ascii")
    sock.sendall(id_field + b"\x00" * (ID_FIELD_LEN - len(id_field)))
    print("2. 리피터 ID 전송:", args.repeater_id)

    server_version = recv_exact(sock, 12)
    print("3. 고객 VNC 서버 버전:", server_version.decode("ascii").strip())

    sock.sendall(b"RFB 003.008\n")

    count = recv_exact(sock, 1)[0]
    if count == 0:
        reason_len = struct.unpack(">I", recv_exact(sock, 4))[0]
        raise SystemExit("보안 협상 실패: " + recv_exact(sock, reason_len).decode())
    types = recv_exact(sock, count)
    print("4. 서버가 제시한 보안 방식:", list(types))

    if 2 not in types:
        raise SystemExit("VNC 인증(2)을 지원하지 않습니다")

    sock.sendall(bytes([2]))
    challenge = recv_exact(sock, 16)
    sock.sendall(des_response(args.password, challenge))

    result = struct.unpack(">I", recv_exact(sock, 4))[0]
    if result != 0:
        raise SystemExit("5. VNC 인증 실패 (비밀번호 불일치)")
    print("5. VNC 인증 성공")

    sock.sendall(bytes([1]))  # ClientInit: shared
    width, height = struct.unpack(">HH", recv_exact(sock, 4))
    recv_exact(sock, 16)
    name_len = struct.unpack(">I", recv_exact(sock, 4))[0]
    name = recv_exact(sock, name_len).decode("latin-1")
    print("6. ServerInit: %dx%d, 데스크톱 이름 '%s'" % (width, height, name))

    # SetEncodings: raw(0) 만 사용
    sock.sendall(struct.pack(">BBHi", 2, 0, 1, 0))
    # FramebufferUpdateRequest 전체 영역
    sock.sendall(struct.pack(">BBHHHH", 3, 0, 0, 0, width, height))

    msg_type = recv_exact(sock, 1)[0]
    if msg_type != 0:
        raise SystemExit("7. 예상치 못한 메시지 타입: %d" % msg_type)
    recv_exact(sock, 1)
    rects = struct.unpack(">H", recv_exact(sock, 2))[0]
    print("7. FramebufferUpdate 수신: 사각형 %d개" % rects)

    x, y, w, h, enc = struct.unpack(">HHHHi", recv_exact(sock, 12))
    pixels = recv_exact(sock, w * h * 4)
    print("8. 첫 사각형 %dx%d (인코딩 %d), 픽셀 %d바이트 수신" % (w, h, enc, len(pixels)))

    sock.close()
    print()
    print("결과: 리피터 경유 원격 화면 수신 성공")
    return 0


if __name__ == "__main__":
    sys.exit(main())
