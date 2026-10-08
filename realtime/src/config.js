/**
 * 파일 위치: realtime/src/config.js
 * 역할: 환경변수에서 실시간 메시지 서버 설정을 읽는다
 */
'use strict';

function num(value, fallback) {
    const n = parseInt(value, 10);
    return Number.isFinite(n) ? n : fallback;
}

module.exports = {
    // 리스닝
    port: num(process.env.REALTIME_PORT, 8091),
    host: process.env.REALTIME_HOST || '0.0.0.0',

    // 포털 (접속 토큰 검증)
    portalOrigin: process.env.PORTAL_ORIGIN || 'http://web',
    sharedSecret: process.env.REALTIME_SHARED_SECRET || '',
    authTimeoutMs: num(process.env.REALTIME_AUTH_TIMEOUT_MS, 5000),

    // 한도
    maxClients: num(process.env.REALTIME_MAX_CLIENTS, 2000),
    maxClientsPerRoom: num(process.env.REALTIME_MAX_PER_ROOM, 50),
    maxPayloadBytes: num(process.env.REALTIME_MAX_PAYLOAD_BYTES, 64 * 1024),
    pingIntervalMs: num(process.env.REALTIME_PING_INTERVAL_MS, 30000),

    logLevel: process.env.REALTIME_LOG_LEVEL || 'info',
};
