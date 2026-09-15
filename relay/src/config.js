/**
 * 파일 위치: relay/src/config.js
 * 역할: 환경변수에서 중계 서버 설정을 읽는다
 */
'use strict';

function num(value, fallback) {
    const n = parseInt(value, 10);
    return Number.isFinite(n) ? n : fallback;
}

module.exports = {
    // 리스닝
    port: num(process.env.RELAY_PORT, 8081),
    host: process.env.RELAY_HOST || '0.0.0.0',

    // 포털 (토큰 검증)
    portalOrigin: process.env.PORTAL_ORIGIN || 'http://web',
    authSecret: process.env.RELAY_AUTH_SHARED_SECRET || '',
    authTimeoutMs: num(process.env.RELAY_AUTH_TIMEOUT_MS, 5000),

    // 세션
    maxSessions: num(process.env.RELAY_MAX_SESSIONS, 200),
    maxFrameBytes: num(process.env.RELAY_MAX_FRAME_BYTES, 4 * 1024 * 1024),
    idleTimeoutMs: num(process.env.RELAY_IDLE_TIMEOUT_MS, 120000),
    pingIntervalMs: num(process.env.RELAY_PING_INTERVAL_MS, 30000),

    // 상대가 끊긴 뒤 재접속을 기다리는 시간 (에이전트 재접속 대비)
    peerGraceMs: num(process.env.RELAY_PEER_GRACE_MS, 15000),

    logLevel: process.env.RELAY_LOG_LEVEL || 'info',
};
