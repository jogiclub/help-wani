/**
 * 파일 위치: realtime/src/hub.js
 * 역할: 방(room) 단위 구독자 관리와 이벤트 전달
 *
 * 방 이름
 *   conv:{대화 id} — 그 대화를 보고 있는 방문자와 상담원
 *   org:{조직 id}  — 그 조직 상담원 콘솔 (목록 갱신용)
 */
'use strict';

const config = require('./config');
const logger = require('./logger');

/** 방 이름 -> 소켓 Set */
const rooms = new Map();

let clientCount = 0;

function canAccept() {
    return clientCount < config.maxClients;
}

/**
 * 소켓을 여러 방에 넣는다. 방이 가득 차면 넣지 않는다.
 */
function join(ws, names) {
    ws.rhRooms = new Set();

    for (const name of names) {
        let members = rooms.get(name);

        if (!members) {
            members = new Set();
            rooms.set(name, members);
        }

        if (members.size >= config.maxClientsPerRoom) {
            logger.warn('방 정원 초과', { room: name, size: members.size });
            continue;
        }

        members.add(ws);
        ws.rhRooms.add(name);
    }

    clientCount++;

    return ws.rhRooms.size > 0;
}

function leave(ws) {
    if (!ws.rhRooms) {
        return;
    }

    for (const name of ws.rhRooms) {
        const members = rooms.get(name);

        if (!members) {
            continue;
        }

        members.delete(ws);

        if (members.size === 0) {
            rooms.delete(name);
        }
    }

    ws.rhRooms = null;
    clientCount = Math.max(0, clientCount - 1);
}

function send(ws, payload) {
    if (ws.readyState !== ws.OPEN) {
        return false;
    }

    try {
        ws.send(JSON.stringify(payload));
        return true;
    } catch (err) {
        logger.warn('전송 실패', { error: err.message });
        return false;
    }
}

/**
 * 방 목록에 이벤트를 보낸다. 같은 소켓이 두 방에 있어도 한 번만 받는다.
 */
function publish(names, event) {
    const sent = new Set();
    let count = 0;

    for (const name of names) {
        const members = rooms.get(name);

        if (!members) {
            continue;
        }

        for (const ws of members) {
            if (sent.has(ws)) {
                continue;
            }

            sent.add(ws);

            if (send(ws, event)) {
                count++;
            }
        }
    }

    return count;
}

function stats() {
    return { clients: clientCount, rooms: rooms.size };
}

module.exports = { canAccept, join, leave, send, publish, stats };
