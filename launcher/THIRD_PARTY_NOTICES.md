# 제3자 소프트웨어 고지

RemoteHelp 런처는 다음 제3자 구성요소를 포함하거나 사용합니다.

## UltraVNC (winvnc.exe 및 관련 DLL)

- 프로젝트: https://github.com/ultravnc/UltraVNC
- 라이선스: GNU General Public License v3.0 or later (GPL-3.0-or-later)
- 사용 범위: 고객 PC 화면 공유 및 원격 제어 엔진. 런처가 별도 프로세스로 실행합니다.

GPL-3.0 은 배포 시 대응하는 소스 코드를 제공할 것을 요구합니다. 본 제품에 포함된
UltraVNC 의 정확한 버전과 빌드에 사용한 소스는 아래에서 받을 수 있습니다.

> 소스 제공 위치: https://example.com/opensource/ultravnc  (배포 전 실제 주소로 교체)

포함된 UltraVNC 버전: (빌드 시 기록)

### 분리 배포 구조

런처(RemoteHelp.exe)는 UltraVNC 와 링크하지 않고 별도 프로세스로 실행하며,
명령행 인자와 설정 파일로만 통신합니다. 그럼에도 한 패키지로 배포하므로
UltraVNC 부분의 소스 제공 의무를 이행합니다.

## noVNC (상담원 웹 뷰어, 포털 측)

- 프로젝트: https://github.com/novnc/noVNC
- 라이선스: MPL-2.0 (일부 파일은 다른 라이선스)
- 사용 범위: 상담원 브라우저 화면 뷰어

## UltraVNC Repeater Linux 포크 (중계 서버 측)

- 프로젝트: https://github.com/tenchman/uvncrepeater-ac (원본: http://koti.mbnet.fi/jtko/uvncrepeater/)
- 라이선스: GPL
- 사용 범위: 중계 서버의 세션 매칭

## websockify

- 프로젝트: https://github.com/novnc/websockify
- 라이선스: LGPL-3.0
- 사용 범위: 중계 서버에서 WebSocket <-> TCP 변환
