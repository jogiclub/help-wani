/**
 * 파일 위치: relay/src/auth.js
 * 역할: 포털 API 로 접속 토큰을 검증한다
 */
'use strict';

const config = require('./config');
const logger = require('./logger');

/**
 * 포털에 토큰 검증을 요청한다.
 * 성공하면 { sessionId, orgId } 를, 실패하면 null 을 돌려준다.
 */
async function verifyToken(role, token, clientIp) {
    if (!token || !/^[a-f0-9]{64}$/.test(token)) {
        logger.warn('토큰 형식 오류', { role, ip: clientIp });
        return null;
    }

    const url = `${config.portalOrigin}/api/relay/auth`
        + `?role=${encodeURIComponent(role)}`
        + `&token=${encodeURIComponent(token)}`
        + `&ip=${encodeURIComponent(clientIp || '')}`;

    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), config.authTimeoutMs);

    try {
        const res = await fetch(url, {
            headers: { 'X-Relay-Secret': config.authSecret },
            signal: controller.signal,
        });

        if (!res.ok) {
            logger.warn('토큰 검증 거절', { role, status: res.status, ip: clientIp });
            return null;
        }

        const body = await res.json();

        if (!body || body.result !== true || !body.data || !body.data.session_id) {
            logger.warn('토큰 검증 응답이 올바르지 않음', { role, ip: clientIp });
            return null;
        }

        return {
            sessionId: String(body.data.session_id),
            orgId: body.data.org_id ? String(body.data.org_id) : null,
        };
    } catch (err) {
        logger.error('토큰 검증 실패', { role, error: err.message, ip: clientIp });
        return null;
    } finally {
        clearTimeout(timer);
    }
}

module.exports = { verifyToken };
