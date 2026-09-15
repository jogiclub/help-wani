/**
 * 파일 위치: relay/src/server.js
 * 역할: RemoteHelp 중계 서버. 에이전트(고객 PC)와 뷰어(상담원) 웹소켓을 세션 단위로 짝지어 중계한다.
 *
 * 프로토콜: docs/protocol.md
 */
'use strict';

const http = require('http');
const { URL } = require('url');
const { WebSocketServer } = require('ws');

const config = require('./config');
const logger = require('./logger');
const auth = require('./auth');
const sessionStore = require('./session');

const CLOSE_UNAUTHORIZED = 4401;
const CLOSE_OVERLOADED = 4503;

const server = http.createServer((req, res) => {
    if (req.url === '/healthz') {
        const stats = sessionStore.stats();
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ status: 'ok', ...stats }));
        return;
    }

    res.writeHead(404, { 'Content-Type': 'text/plain' });
    res.end('not found');
});

// TLS 는 앞단 nginx 가 처리하므로 여기서는 평문 웹소켓을 받는다.
const wss = new WebSocketServer({ noServer: true, maxPayload: config.maxFrameBytes });

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

server.on('upgrade', async (req, socket, head) => {
    let url;

    try {
        url = new URL(req.url, 'http://localhost');
    } catch {
        socket.destroy();
        return;
    }

    const role = url.pathname === '/agent' ? 'agent'
        : url.pathname === '/viewer' ? 'viewer'
        : null;

    if (!role) {
        socket.write('HTTP/1.1 404 Not Found\r\n\r\n');
        socket.destroy();
        return;
    }

    const ip = clientIp(req);
    const token = url.searchParams.get('token');

    // 업그레이드를 마친 뒤 검증 결과에 따라 닫는다.
    // (핸드셰이크 전에 끊으면 브라우저가 이유를 알 수 없다)
    wss.handleUpgrade(req, socket, head, async (ws) => {
        const verified = await auth.verifyToken(role, token, ip);

        if (!verified) {
            sessionStore.sendControl(ws, { type: 'error', message: '접속 인증에 실패했습니다.' });
            ws.close(CLOSE_UNAUTHORIZED, 'unauthorized');
            return;
        }

        const session = sessionStore.getOrCreate(verified.sessionId);

        if (!session) {
            logger.error('세션 수 한도 초과', { max: config.maxSessions });
            sessionStore.sendControl(ws, { type: 'error', message: '서버가 혼잡합니다.' });
            ws.close(CLOSE_OVERLOADED, 'too many sessions');
            return;
        }

        logger.info('접속', { sessionId: session.id, role, ip });
        sessionStore.attach(session, role, ws);
        wss.emit('connection', ws, req);
    });
});

wss.on('connection', (ws) => {
    ws.on('message', (data, isBinary) => {
        ws.rhAlive = true;

        if (isBinary) {
            sessionStore.relay(ws, data);
            return;
        }

        // 텍스트는 제어 메시지. 현재는 클라이언트가 보낼 일이 없으므로 무시한다.
        logger.debug('텍스트 메시지 무시', { sessionId: ws.rhSession && ws.rhSession.id });
    });

    ws.on('pong', () => { ws.rhAlive = true; });

    ws.on('close', (code) => {
        logger.info('접속 종료', {
            sessionId: ws.rhSession && ws.rhSession.id,
            role: ws.rhRole,
            code,
        });
        sessionStore.detach(ws);
    });

    ws.on('error', (err) => {
        logger.warn('소켓 오류', {
            sessionId: ws.rhSession && ws.rhSession.id,
            role: ws.rhRole,
            error: err.message,
        });
    });
});

// 죽은 연결 정리
const heartbeat = setInterval(() => {
    for (const ws of wss.clients) {
        if (ws.rhAlive === false) {
            logger.warn('응답 없는 연결을 닫음', {
                sessionId: ws.rhSession && ws.rhSession.id, role: ws.rhRole,
            });
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
    logger.info('중계 서버 시작', {
        host: config.host,
        port: config.port,
        portal: config.portalOrigin,
    });
});
