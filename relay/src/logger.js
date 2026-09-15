/**
 * 파일 위치: relay/src/logger.js
 * 역할: 표준 출력 기반 로그 (systemd / docker 가 수집)
 */
'use strict';

const config = require('./config');

const LEVELS = { error: 0, warn: 1, info: 2, debug: 3 };
const current = LEVELS[config.logLevel] ?? LEVELS.info;

function write(level, message, meta) {
    if (LEVELS[level] > current) {
        return;
    }

    const line = {
        ts: new Date().toISOString(),
        level,
        message,
        ...(meta || {}),
    };

    process.stdout.write(JSON.stringify(line) + '\n');
}

module.exports = {
    error: (m, meta) => write('error', m, meta),
    warn: (m, meta) => write('warn', m, meta),
    info: (m, meta) => write('info', m, meta),
    debug: (m, meta) => write('debug', m, meta),
};
