#!/usr/bin/env python3
"""
파일 위치: scripts/viewer-probe.py
역할: 브라우저 없이 상담원 뷰어 입장에서 접속해 화면 수신과 입력 전송을 검증한다.

중계 서버, 프로토콜, 포털 토큰 검증까지 한 번에 확인한다. (docs/protocol.md)
"""
import argparse
import asyncio
import io
import ssl
import struct
import sys

import websockets

SCREEN_INFO, TILE_FRAME, FRAME_END, CURSOR_POS, CLIPBOARD, PONG, DISPLAY_CHANGED = range(1, 8)
MOUSE_MOVE, MOUSE_BUTTON, MOUSE_WHEEL, KEY, CLIPBOARD_SET, \
    CTRL_ALT_DEL, SET_QUALITY, REQUEST_FULL_FRAME, PING = range(0x10, 0x19)


def decode(data):
    kind = data[0]

    if kind == SCREEN_INFO:
        width, height, monitors = struct.unpack(">HHB", data[1:6])
        return ("screen_info", {"width": width, "height": height, "monitors": monitors})

    if kind == TILE_FRAME:
        seq, x, y, w, h, fmt, length = struct.unpack(">IHHHHBI", data[1:18])
        return ("tile", {"seq": seq, "x": x, "y": y, "w": w, "h": h,
                         "format": fmt, "data": data[18:18 + length]})

    if kind == FRAME_END:
        seq, tiles, elapsed = struct.unpack(">IHH", data[1:9])
        return ("frame_end", {"seq": seq, "tiles": tiles, "elapsed": elapsed})

    if kind == CURSOR_POS:
        x, y = struct.unpack(">HH", data[1:5])
        return ("cursor", {"x": x, "y": y})

    if kind == PONG:
        return ("pong", {"echo": struct.unpack(">I", data[1:5])[0]})

    return ("other", {"type": kind})


def check_jpeg(payload):
    """타일이 실제로 디코딩 가능한 JPEG 인지 확인한다(PIL 이 있으면)."""
    try:
        from PIL import Image
        image = Image.open(io.BytesIO(payload))
        image.load()
        return image.size
    except ImportError:
        return None
    except Exception:
        return False


async def run(args):
    ctx = ssl.create_default_context()

    if args.insecure:
        ctx.check_hostname = False
        ctx.verify_mode = ssl.CERT_NONE

    url = f"{args.url}?token={args.token}"
    print(f"1. 뷰어 접속: {args.url}")

    stats = {"tiles": 0, "frames": 0, "bytes": 0, "screen": None, "bad": 0}

    async with websockets.connect(url, ssl=ctx, max_size=8 * 1024 * 1024) as ws:
        print("2. 중계 서버 연결됨")

        deadline = asyncio.get_event_loop().time() + args.seconds
        sent_input = False

        while asyncio.get_event_loop().time() < deadline:
            try:
                remaining = deadline - asyncio.get_event_loop().time()
                message = await asyncio.wait_for(ws.recv(), timeout=max(0.1, remaining))
            except asyncio.TimeoutError:
                break
            except websockets.ConnectionClosed as e:
                print(f"   연결 종료: code={e.code} reason={e.reason}")
                break

            if isinstance(message, str):
                print(f"3. 제어 메시지: {message}")

                if '"peer_connected"' in message:
                    await ws.send(struct.pack(">B", REQUEST_FULL_FRAME))
                    print("4. 전체 화면 요청 전송")
                continue

            stats["bytes"] += len(message)
            name, payload = decode(message)

            if name == "screen_info":
                stats["screen"] = (payload["width"], payload["height"])
                print(f"5. 화면 정보 수신: {payload['width']}x{payload['height']}, "
                      f"모니터 {payload['monitors']}개")

            elif name == "tile":
                stats["tiles"] += 1
                size = check_jpeg(payload["data"])

                if size is False:
                    stats["bad"] += 1
                elif stats["tiles"] == 1 and size:
                    print(f"6. 첫 타일 수신: ({payload['x']},{payload['y']}) "
                          f"{payload['w']}x{payload['h']} JPEG {len(payload['data'])}바이트, "
                          f"디코딩 {size[0]}x{size[1]}")

            elif name == "frame_end":
                stats["frames"] += 1

                if stats["frames"] == 1:
                    print(f"7. 첫 프레임 완료: 타일 {payload['tiles']}개, "
                          f"인코딩 {payload['elapsed']}ms")

                    # 입력 전송도 한 번 확인한다.
                    if not sent_input and stats["screen"]:
                        w, h = stats["screen"]
                        await ws.send(struct.pack(">BHH", MOUSE_MOVE, w // 2, h // 2))
                        await ws.send(struct.pack(">BHHBB", MOUSE_BUTTON, w // 2, h // 2, 0, 1))
                        await ws.send(struct.pack(">BHHBB", MOUSE_BUTTON, w // 2, h // 2, 0, 0))
                        await ws.send(struct.pack(">BBHB", KEY, 1, 0x41, 0))
                        await ws.send(struct.pack(">BBHB", KEY, 0, 0x41, 0))
                        await ws.send(struct.pack(">BBBB", SET_QUALITY, 60, 8, 100))
                        sent_input = True
                        print("8. 입력 이벤트 전송 (마우스 이동/클릭, 키 A, 화질 변경)")

                if stats["frames"] >= args.frames:
                    break

    print()
    print(f"   수신 프레임 {stats['frames']}개, 타일 {stats['tiles']}개, "
          f"{stats['bytes'] / 1024:.1f}KB")

    if stats["bad"]:
        print(f"   손상된 타일 {stats['bad']}개")
        return 1

    if stats["frames"] < args.frames:
        print(f"   기대 프레임 {args.frames}개를 받지 못했습니다.")
        return 1

    print()
    print("결과: 중계 서버 경유 원격 화면 수신 성공")
    return 0


def main():
    p = argparse.ArgumentParser()
    p.add_argument("--url", default="wss://localhost:8443/viewer")
    p.add_argument("--token", required=True)
    p.add_argument("--frames", type=int, default=3, help="이만큼 프레임을 받으면 성공으로 본다")
    p.add_argument("--seconds", type=float, default=20, help="최대 대기 시간")
    p.add_argument("--insecure", action="store_true", help="자체 서명 인증서 허용(개발용)")
    return asyncio.run(run(p.parse_args()))


if __name__ == "__main__":
    sys.exit(main())
