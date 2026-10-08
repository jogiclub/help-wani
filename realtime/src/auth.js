/**
 * 파일 위치: realtime/src/auth.js
 * 역할: 포털 API 로 구독 토큰을 검증한다
 *
 * 토큰 서명 키는 포털만 가진다. 이 서버는 검증 결과(구독할 방 목록)만 받는다.
 */
'use strict';

const config = require('./config');
const logger = require('./logger');

/**
 * 포털에 토큰 검증을 요청한다.
 * 성공하면 { role, orgId, conversationId, rooms }, 실패하면 null 을 돌려준다.
 */
async function verifyToken(token, clientIp) {
    if (!token || token.length > 1024) {
        return null;
    }

    const url = `${config.portalOrigin}/api/realtime/auth`
        + `?token=${encodeURIComponent(token)}`
        + `&ip=${encodeURIComponent(clientIp || '')}`;

    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), config.authTimeoutMs);

    try {
        const res = await fetch(url, {
            headers: { 'X-Realtime-Secret': config.sharedSecret },
            signal: controller.signal,
        });

        if (!res.ok) {
            logger.warn('토큰 검증 거절', { status: res.status, ip: clientIp });
            return null;
        }

        const body = await res.json();

        if (!body || body.result !== true || !body.data || !Array.isArray(body.data.rooms)) {
            logger.warn('토큰 검증 응답이 올바르지 않음', { ip: clientIp });
            return null;
        }

        const rooms = body.data.rooms
            .filter((name) => typeof name === 'string' && /^(conv|org):[0-9]{1,20}$/.test(name));

        if (rooms.length === 0) {
            return null;
        }

        return {
            role: String(body.data.role || ''),
            orgId: body.data.org_id ? String(body.data.org_id) : null,
            conversationId: body.data.conversation_id ? String(body.data.conversation_id) : null,
            rooms,
        };
    } catch (err) {
        logger.error('토큰 검증 실패', { error: err.message, ip: clientIp });
        return null;
    } finally {
        clearTimeout(timer);
    }
}

/**
 * 포털이 보낸 발행 요청인지 확인한다. 공유 비밀이 비어 있으면 검사하지 않는다(개발용).
 */
function isTrustedPublisher(req) {
    if (config.sharedSecret === '') {
        return true;
    }

    const sent = req.headers['x-realtime-secret'];

    if (typeof sent !== 'string' || sent.length !== config.sharedSecret.length) {
        return false;
    }

    // 길이가 같을 때만 비교하므로 타이밍 차이가 값을 드러내지 않는다.
    let diff = 0;

    for (let i = 0; i < sent.length; i++) {
        diff |= sent.charCodeAt(i) ^ config.sharedSecret.charCodeAt(i);
    }

    return diff === 0;
}

module.exports = { verifyToken, isTrustedPublisher };
