/**
 * 파일 위치: realtime/src/server.js
 * 역할: 상담 실시간 메시지 서버. 방문자 위젯과 상담원 콘솔에 새 메시지를 밀어 준다.
 *
 * 메시지 저장은 포털(CI3)이 한다. 이 서버는 저장된 사실을 구독자에게 전달만 한다.
 *
 *   위젯/콘솔  --ws /ws?token=...-->  이 서버  (구독)
 *   포털       --POST /publish----->  이 서버  (발행)
 *
 * TLS 는 앞단 nginx 가 처리하므로 여기서는 평문으로 받는다.
 */
'use strict';

const http = require('http');
const { URL } = require('url');
const { WebSocketServer } = require('ws');

const config = require('./config');
const logger = require('./logger');
const auth = require('./auth');
const hub = require('./hub');

const CLOSE_UNAUTHORIZED = 4401;
const CLOSE_OVERLOADED = 4503;

/** 발행 요청 본문 크기 상한 */
const MAX_BODY_BYTES = 256 * 1024;

function readJsonBody(req) {
    return new Promise((resolve, reject) => {
        const chunks = [];
        let size = 0;

        req.on('data', (chunk) => {
            size += chunk.length;

            if (size > MAX_BODY_BYTES) {
                reject(new Error('본문이 너무 큽니다'));
                req.destroy();
                return;
            }

            chunks.push(chunk);
        });

        req.on('end', () => {
            try {
                resolve(JSON.parse(Buffer.concat(chunks).toString('utf8')));
            } catch (err) {
                reject(err);
            }
        });

        req.on('error', reject);
    });
}

function json(res, status, body) {
    res.writeHead(status, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify(body));
}

const server = http.createServer(async (req, res) => {
    if (req.url === '/healthz') {
        json(res, 200, { status: 'ok', ...hub.stats() });
        return;
    }

    if (req.url === '/publish' && req.method === 'POST') {
        if (!auth.isTrustedPublisher(req)) {
            logger.warn('발행 권한 없음');
            json(res, 403, { result: false });
            return;
        }

        let body;

        try {
            body = await readJsonBody(req);
        } catch (err) {
            json(res, 400, { result: false, message: err.message });
            return;
        }

        const names = Array.isArray(body && body.rooms)
            ? body.rooms.filter((n) => typeof n === 'string' && /^(conv|org):[0-9]{1,20}$/.test(n))
            : [];

        if (names.length === 0 || !body.event) {
            json(res, 400, { result: false, message: 'rooms 와 event 가 필요합니다' });
            return;
        }

        const delivered = hub.publish(names, body.event);
        logger.debug('발행', { rooms: names, delivered });

        json(res, 200, { result: true, delivered });
        return;
    }

    res.writeHead(404, { 'Content-Type': 'text/plain' });
    res.end('not found');
});

const wss = new WebSocketServer({ noServer: true, maxPayload: config.maxPayloadBytes });

/**
 * 프록시 뒤에서 실제 클라이언트 IP 를 얻는다.
 */
function clientIp(req) {
    const forwarded = req.headers['x-forwarded-for'];

    if (typeof forwarded === 'string' && forwarded.length > 0) {
        return forwarded.split(',')[0].trim();
    }

    return req.socket.remoteAddress || '';
}

server.on('upgrade', (req, socket, head) => {
    let url;

    try {
        url = new URL(req.url, 'http://localhost');
    } catch {
        socket.destroy();
        return;
    }

    if (url.pathname !== '/ws') {
        socket.write('HTTP/1.1 404 Not Found\r\n\r\n');
        socket.destroy();
        return;
    }

    const ip = clientIp(req);
    const token = url.searchParams.get('token');

    // 업그레이드를 마친 뒤 결과에 따라 닫는다. 그래야 클라이언트가 이유를 알 수 있다.
    wss.handleUpgrade(req, socket, head, async (ws) => {
        if (!hub.canAccept()) {
            hub.send(ws, { type: 'error', message: '서버가 혼잡합니다.' });
            ws.close(CLOSE_OVERLOADED, 'too many clients');
            return;
        }

        const verified = await auth.verifyToken(token, ip);

        if (!verified) {
            hub.send(ws, { type: 'error', message: '접속 인증에 실패했습니다.' });
            ws.close(CLOSE_UNAUTHORIZED, 'unauthorized');
            return;
        }

        if (!hub.join(ws, verified.rooms)) {
            hub.send(ws, { type: 'error', message: '서버가 혼잡합니다.' });
            ws.close(CLOSE_OVERLOADED, 'room full');
            return;
        }

        ws.rhRole = verified.role;
        ws.rhAlive = true;

        logger.info('구독 시작', { role: verified.role, rooms: verified.rooms, ip });
        hub.send(ws, { type: 'ready', rooms: verified.rooms });
        wss.emit('connection', ws, req);
    });
});

wss.on('connection', (ws) => {
    ws.on('message', () => {
        // 구독 전용이다. 메시지 전송은 포털 HTTP API 로만 받는다.
        ws.rhAlive = true;
    });

    ws.on('pong', () => { ws.rhAlive = true; });

    ws.on('close', (code) => {
        logger.info('구독 종료', { role: ws.rhRole, code });
        hub.leave(ws);
    });

    ws.on('error', (err) => {
        logger.warn('소켓 오류', { role: ws.rhRole, error: err.message });
    });
});

// 죽은 연결 정리
const heartbeat = setInterval(() => {
    for (const ws of wss.clients) {
        if (ws.rhAlive === false) {
            logger.warn('응답 없는 연결을 닫음', { role: ws.rhRole });
            ws.terminate();
            continue;
        }

        ws.rhAlive = false;
        ws.ping();
    }
}, config.pingIntervalMs);

function shutdown(signal) {
    logger.info('종료 신호 수신', { signal });
    clearInterval(heartbeat);

    for (const ws of wss.clients) {
        ws.close(1001, 'server shutting down');
    }

    server.close(() => process.exit(0));
    setTimeout(() => process.exit(0), 3000).unref();
}

process.on('SIGTERM', () => shutdown('SIGTERM'));
process.on('SIGINT', () => shutdown('SIGINT'));

server.listen(config.port, config.host, () => {
    logger.info('실시간 메시지 서버 시작', {
        host: config.host,
        port: config.port,
        portal: config.portalOrigin,
    });
});
