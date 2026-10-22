/**
 * 파일 위치: backend/assets/js/bs.js
 * 역할: Bootstrap 6(ESM 전용)을 불러와 일반 스크립트(common.js, 화면 JS)가 쓸 수 있게 window.RHBootstrap 에 넘긴다.
 *
 * Bootstrap 6 은 전역 객체를 만들지 않는다(마이그레이션 문서). 이 모듈은 지연 실행되므로
 * 화면 스크립트는 읽히는 순간이 아니라 사용자 동작 안에서, 또는 'rh:bootstrap-ready' 이벤트 뒤에 Bootstrap 을 쓴다.
 * 버전을 올리면 이 파일의 import 경로와 layouts/_head.html 의 CSS 경로를 함께 고친다(check-conventions 가 대조한다).
 */
import { Dialog, Drawer, Menu, Tab, Toast, Tooltip } from '../vendor/bootstrap/6.0.0-alpha.1/bootstrap.bundle.min.js';

window.RHBootstrap = { Dialog, Drawer, Menu, Tab, Toast, Tooltip };
document.dispatchEvent(new Event('rh:bootstrap-ready'));
