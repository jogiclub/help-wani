# 제3자 소프트웨어 고지

RemoteHelp 런처는 다음 제3자 구성요소를 포함하거나 사용합니다.

## UltraVNC (winvnc.exe 및 관련 DLL)

- 프로젝트: https://github.com/ultravnc/UltraVNC
- 라이선스: GNU General Public License v3.0 or later (GPL-3.0-or-later)
- 사용 범위: 고객 PC 화면 공유 및 원격 제어 엔진. 런처가 별도 프로세스로 실행합니다.
- **포함된 버전: 1.8.3.0 (x64)**
- 배포본 출처: https://uvnc.eu/download/1800/UltraVNC_1830.zip
  (uvnc.com 공식 다운로드 페이지에서 연결되는 주소)
- 배포본 zip SHA-256: `11163a0b9b86321bf6403eb0cf84b81f7230ce32e6291377e1fe5beb987f7e4f`
- 포함 파일별 SHA-256: `launcher/vendor/ultravnc/SHA256SUMS`

### 대응 소스 코드

GPL-3.0 은 배포 시 대응하는 소스 코드를 제공할 것을 요구합니다.
1.8.3.0 에 대응하는 소스는 UltraVNC 공식 저장소의 다음 커밋입니다.

- https://github.com/ultravnc/UltraVNC commit `33cee1a22e84e395c2ed09ad94fb4ad0b2f9c94b` (커밋 메시지 "1.8.3.0")
- 스냅샷 내려받기: `./scripts/fetch-ultravnc.sh --with-source`

> 소스 제공 위치: https://example.com/opensource/ultravnc  (배포 전 실제 주소로 교체)

주의: UltraVNC 공식 저장소에는 릴리스 태그가 없어 위 커밋을 버전 표기 기준으로 특정했습니다.
스토어 배포 전에 UltraVNC 측에 해당 바이너리의 대응 소스가 맞는지 확인하고,
그 스냅샷을 직접 호스팅하는 것을 권장합니다.

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
