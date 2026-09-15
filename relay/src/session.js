/**
 * 파일 위치: relay/src/session.js
 * 역할: 세션별 에이전트/뷰어 짝짓기와 바이너리 중계
 */
'use strict';

const config = require('./config');
const logger = require('./logger');

const CLOSE_PEER_GONE = 4410;
const CLOSE_DUPLICATE = 4409;

/** sessionId -> Session */
const sessions = new Map();

class Session {
    constructor(id) {
        this.id = id;
        this.agent = null;
        this.viewer = null;
        this.createdAt = Date.now();
        this.bytesAgentToViewer = 0;
        this.bytesViewerToAgent = 0;
        this.graceTimer = null;
    }

    peerOf(role) {
        return role === 'agent' ? this.viewer : this.agent;
    }

    isEmpty() {
        return !this.agent && !this.viewer;
    }
}

function getOrCreate(sessionId) {
    let session = sessions.get(sessionId);

    if (!session) {
        if (sessions.size >= config.maxSessions) {
            return null;
        }
        session = new Session(sessionId);
        sessions.set(sessionId, session);
        logger.info('세션 생성', { sessionId, total: sessions.size });
    }

    return session;
}

function sendControl(ws, payload) {
    if (ws && ws.readyState === ws.OPEN) {
        ws.send(JSON.stringify(payload));
    }
}

/**
 * 접속한 소켓을 세션에 등록한다.
 * 같은 역할이 이미 있으면 기존 연결을 끊고 새 연결로 교체한다(재접속 대비).
 */
function attach(session, role, ws) {
    const existing = session[role];

    if (existing && existing.readyState === existing.OPEN) {
        logger.warn('같은 역할 중복 접속, 기존 연결을 닫음', { sessionId: session.id, role });
        existing.close(CLOSE_DUPLICATE, 'replaced by new connection');
    }

    session[role] = ws;
    ws.rhRole = role;
    ws.rhSession = session;
    ws.rhAlive = true;

    if (session.graceTimer) {
        clearTimeout(session.graceTimer);
        session.graceTimer = null;
    }

    sendControl(ws, { type: 'ready', session_id: session.id, role });

    const peer = session.peerOf(role);

    if (peer) {
        // 양쪽 모두 상대가 붙었음을 알린다.
        sendControl(ws, { type: 'peer_connected', role: peer.rhRole });
        sendControl(peer, { type: 'peer_connected', role });
        logger.info('세션 연결 완료', { sessionId: session.id });
    }
}

/**
 * 바이너리 프레임을 상대에게 그대로 전달한다.
 */
function relay(ws, data) {
    const session = ws.rhSession;

    if (!session) {
        return;
    }

    if (data.length > config.maxFrameBytes) {
        logger.warn('프레임 크기 초과로 연결을 닫음', {
            sessionId: session.id, role: ws.rhRole, size: data.length,
        });
        ws.close(1009, 'frame too large');
        return;
    }

    const peer = session.peerOf(ws.rhRole);

    if (!peer || peer.readyState !== peer.OPEN) {
        return;   // 상대가 아직 없거나 끊긴 상태. 화면 프레임은 버린다.
    }

    if (ws.rhRole === 'agent') {
        session.bytesAgentToViewer += data.length;
    } else {
        session.bytesViewerToAgent += data.length;
    }

    peer.send(data, { binary: true });
}

/**
 * 연결이 끊겼을 때 정리한다.
 */
function detach(ws) {
    const session = ws.rhSession;
    const role = ws.rhRole;

    if (!session || session[role] !== ws) {
        return;
    }

    session[role] = null;

    const peer = session.peerOf(role);

    if (peer) {
        sendControl(peer, { type: 'peer_disconnected', role });

        // 에이전트가 끊기면 잠시 재접속을 기다린 뒤 뷰어도 닫는다.
        if (role === 'agent') {
            session.graceTimer = setTimeout(() => {
                if (!session.agent && peer.readyState === peer.OPEN) {
                    peer.close(CLOSE_PEER_GONE, 'agent gone');
                }
            }, config.peerGraceMs);
        }
    }

    if (session.isEmpty()) {
        if (session.graceTimer) {
            clearTimeout(session.graceTimer);
        }
        sessions.delete(session.id);
        logger.info('세션 종료', {
            sessionId: session.id,
            durationSec: Math.round((Date.now() - session.createdAt) / 1000),
            agentToViewerKB: Math.round(session.bytesAgentToViewer / 1024),
            viewerToAgentKB: Math.round(session.bytesViewerToAgent / 1024),
            total: sessions.size,
        });
    }
}

function stats() {
    let paired = 0;

    for (const s of sessions.values()) {
        if (s.agent && s.viewer) {
            paired++;
        }
    }

    return { sessions: sessions.size, paired };
}

module.exports = { getOrCreate, attach, relay, detach, stats, sendControl };
