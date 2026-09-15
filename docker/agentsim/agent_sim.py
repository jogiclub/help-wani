#!/usr/bin/env python3
"""
파일 위치: docker/agentsim/agent_sim.py
역할: 윈도우 없이 중계 서버와 프로토콜을 검증하기 위한 고객 PC 에이전트 모의 구현

실제 런처(C#)와 같은 프레임 형식을 쓴다. 화면은 PIL 로 합성해 만들고,
바뀐 타일만 JPEG 으로 보내는 동작까지 동일하게 재현한다. (docs/protocol.md)
"""
import asyncio
import io
import os
import ssl
import struct
import sys
import time

from PIL import Image, ImageDraw
import websockets

TILE = 128

# 에이전트 -> 뷰어
SCREEN_INFO, TILE_FRAME, FRAME_END, CURSOR_POS, CLIPBOARD, PONG, DISPLAY_CHANGED = range(1, 8)

# 뷰어 -> 에이전트
MOUSE_MOVE, MOUSE_BUTTON, MOUSE_WHEEL, KEY, CLIPBOARD_SET, \
    CTRL_ALT_DEL, SET_QUALITY, REQUEST_FULL_FRAME, PING = range(0x10, 0x19)


class SyntheticScreen:
    """움직이는 도형과 시계가 있는 가상 화면. 타일 변경 감지를 검증하기 좋다."""

    def __init__(self, width, height):
        self.width = width
        self.height = height
        self.frame_no = 0

    def render(self):
        self.frame_no += 1
        image = Image.new("RGB", (self.width, self.height), (24, 28, 38))
        draw = ImageDraw.Draw(image)

        # 고정 배경 (바뀌지 않는 타일 -> 전송되지 않아야 한다)
        draw.rectangle([40, 40, self.width - 40, 120], fill=(31, 111, 235))
        draw.text((60, 70), "RemoteHelp 에이전트 모의 화면", fill=(255, 255, 255))

        # 움직이는 사각형 (바뀌는 타일)
        x = 60 + ((self.frame_no * 37) % max(1, self.width - 260))
        y = 200 + ((self.frame_no * 17) % max(1, self.height - 400))
        draw.rectangle([x, y, x + 180, y + 120], fill=(240, 180, 40))
        draw.text((x + 20, y + 50), f"frame {self.frame_no}", fill=(20, 20, 20))

        # 시계
        draw.text((60, self.height - 60), time.strftime("%H:%M:%S"), fill=(200, 200, 200))

        return image


class TileEncoder:
    def __init__(self, width, height, quality=70):
        self.width = width
        self.height = height
        self.quality = quality
        self.cols = (width + TILE - 1) // TILE
        self.rows = (height + TILE - 1) // TILE
        self.hashes = [None] * (self.cols * self.rows)

    def invalidate(self):
        self.hashes = [None] * (self.cols * self.rows)

    def changed_tiles(self, image):
        out = []

        for row in range(self.rows):
            for col in range(self.cols):
                x, y = col * TILE, row * TILE
                w = min(TILE, self.width - x)
                h = min(TILE, self.height - y)

                if w <= 0 or h <= 0:
                    continue

                tile = image.crop((x, y, x + w, y + h))
                digest = hash(tile.tobytes())
                index = row * self.cols + col

                if self.hashes[index] == digest:
                    continue

                self.hashes[index] = digest

                buf = io.BytesIO()
                tile.save(buf, format="JPEG", quality=self.quality)
                out.append((x, y, w, h, buf.getvalue()))

        return out


def pack_screen_info(width, height):
    return struct.pack(">BHHB", SCREEN_INFO, width, height, 1) + \
        struct.pack(">hhHH", 0, 0, width, height)


def pack_tile(seq, x, y, w, h, data):
    return struct.pack(">BIHHHHBI", TILE_FRAME, seq, x, y, w, h, 1, len(data)) + data


def pack_frame_end(seq, tiles, elapsed_ms):
    return struct.pack(">BIHH", FRAME_END, seq, tiles, min(elapsed_ms, 65535))


def pack_cursor(x, y):
    return struct.pack(">BHH", CURSOR_POS, x, y)


def parse_command(data):
    """뷰어가 보낸 프레임을 사람이 읽을 수 있는 형태로 바꾼다."""
    if not data:
        return None

    kind = data[0]

    if kind == MOUSE_MOVE and len(data) >= 5:
        x, y = struct.unpack(">HH", data[1:5])
        return ("mouse_move", {"x": x, "y": y})

    if kind == MOUSE_BUTTON and len(data) >= 7:
        x, y, button, down = struct.unpack(">HHBB", data[1:7])
        return ("mouse_button", {"x": x, "y": y, "button": button, "down": bool(down)})

    if kind == MOUSE_WHEEL and len(data) >= 7:
        x, y, delta = struct.unpack(">HHh", data[1:7])
        return ("mouse_wheel", {"x": x, "y": y, "delta": delta})

    if kind == KEY and len(data) >= 5:
        down, vk, extended = struct.unpack(">BHB", data[1:5])
        return ("key", {"down": bool(down), "vk": vk, "extended": bool(extended)})

    if kind == CLIPBOARD_SET and len(data) >= 5:
        length = struct.unpack(">I", data[1:5])[0]
        return ("clipboard", {"text": data[5:5 + length].decode("utf-8", "replace")})

    if kind == SET_QUALITY and len(data) >= 4:
        return ("set_quality", {"quality": data[1], "fps": data[2], "scale": data[3]})

    if kind == REQUEST_FULL_FRAME:
        return ("request_full_frame", {})

    if kind == CTRL_ALT_DEL:
        return ("ctrl_alt_del", {})

    if kind == PING and len(data) >= 5:
        return ("ping", {"echo": struct.unpack(">I", data[1:5])[0]})

    return ("unknown", {"type": kind})


async def run():
    url = os.environ.get("RELAY_URL", "wss://relay:8443/agent")
    token = os.environ.get("AGENT_TOKEN", "")
    width = int(os.environ.get("SCREEN_WIDTH", "1280"))
    height = int(os.environ.get("SCREEN_HEIGHT", "800"))
    max_seconds = int(os.environ.get("MAX_SECONDS", "120"))

    if not token:
        print("[agent] AGENT_TOKEN 이 필요합니다", flush=True)
        return 1

    ctx = ssl.create_default_context()
    ctx.check_hostname = False          # 검증용 자체 서명 인증서
    ctx.verify_mode = ssl.CERT_NONE

    screen = SyntheticScreen(width, height)
    encoder = TileEncoder(width, height)

    state = {"peer": False, "full": True, "quality": 70, "fps": 10, "seq": 0, "running": True}

    print(f"[agent] 접속: {url}", flush=True)

    async with websockets.connect(f"{url}?token={token}", ssl=ctx, max_size=8 * 1024 * 1024) as ws:
        print("[agent] 중계 서버 연결됨", flush=True)
        await ws.send(pack_screen_info(width, height))

        async def receive():
            async for message in ws:
                if isinstance(message, str):
                    print(f"[agent] 제어 메시지: {message}", flush=True)
                    if '"peer_connected"' in message:
                        state["peer"] = True
                        state["full"] = True
                        # 뷰어는 자기가 붙기 전에 보낸 화면 정보를 받지 못한다. 다시 보낸다.
                        await ws.send(pack_screen_info(width, height))
                    elif '"peer_disconnected"' in message:
                        state["peer"] = False
                    continue

                name, payload = parse_command(message)
                print(f"[agent] 입력 수신: {name} {payload}", flush=True)

                if name == "request_full_frame":
                    state["full"] = True
                elif name == "set_quality":
                    encoder.quality = payload["quality"]
                    state["fps"] = payload["fps"]
                elif name == "ping":
                    await ws.send(struct.pack(">BI", PONG, payload["echo"]))

            state["running"] = False

        async def capture():
            started = time.time()

            while state["running"] and time.time() - started < max_seconds:
                if not state["peer"]:
                    await asyncio.sleep(0.2)
                    continue

                if state["full"]:
                    state["full"] = False
                    encoder.invalidate()

                begin = time.time()
                image = screen.render()
                tiles = encoder.changed_tiles(image)
                state["seq"] += 1

                for (x, y, w, h, data) in tiles:
                    await ws.send(pack_tile(state["seq"], x, y, w, h, data))

                elapsed = int((time.time() - begin) * 1000)
                await ws.send(pack_frame_end(state["seq"], len(tiles), elapsed))
                await ws.send(pack_cursor((screen.frame_no * 7) % width, (screen.frame_no * 5) % height))

                if state["seq"] % 10 == 0:
                    print(f"[agent] {state['seq']}프레임 전송, 마지막 타일 {len(tiles)}개", flush=True)

                await asyncio.sleep(max(0.0, (1.0 / max(1, state["fps"])) - (time.time() - begin)))

        await asyncio.gather(receive(), capture())

    print("[agent] 종료", flush=True)
    return 0


if __name__ == "__main__":
    sys.exit(asyncio.run(run()))
