# 배포 파일 폴더

고객 접속 페이지(`/{org_code}`)의 다운로드 버튼이 내려주는 실행 파일을 여기에 둡니다.

```
portal/assets/downloads/
  RemoteHelp.exe          <- 여기에 올린다 (저장소에는 포함하지 않음)
  RemoteHelp.exe.sha256   <- 포털이 자동 생성하는 해시 캐시
```

## 파일 만들기

윈도우에서 단일 실행 파일로 빌드합니다.

```powershell
.\launcher\scripts\build-exe.ps1
```

산출물을 `portal/assets/downloads/RemoteHelp.exe` 로 복사하면 페이지에 바로 반영됩니다.
포털이 파일 크기, 배포일, SHA-256 을 읽어 표시합니다.

## 조직 코드 전달 방식

다운로드 시 파일 이름이 `RemoteHelp_{조직코드}.exe` 로 내려갑니다.
런처는 자기 실행 파일 이름에서 조직 코드를 읽어 시작 화면에 반영하므로,
고객이 어느 회사 상담인지 따로 고를 필요가 없습니다.

## 코드 서명

Store 배포본은 Microsoft 가 서명하지만, **이 직접 다운로드 파일은 직접 서명해야 합니다.**
서명하지 않으면 SmartScreen 경고가 뜨고 일부 백신이 차단합니다.

```powershell
signtool sign /fd SHA256 /tr http://timestamp.digicert.com /td SHA256 `
    /f 인증서.pfx /p 비밀번호 RemoteHelp.exe
```

인증서 선택은 `docs/code-signing.md` 를 보세요. 요점만 적으면,

- **EV 인증서를 SmartScreen 때문에 살 필요는 없습니다.** 2024년에 EV 의 즉시 통과 혜택이 없어져
  지금은 OV 와 동작이 같습니다.
- 2023년 6월부터 OV 인증서도 개인키를 HSM 이나 하드웨어 토큰에 보관해야 합니다.
- 어떤 인증서든 새 파일은 평판이 쌓일 때까지 경고가 납니다. **같은 서명 주체로 계속 배포**해야
  평판이 누적됩니다.
