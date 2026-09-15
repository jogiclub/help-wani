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

OV 인증서는 평판이 쌓일 때까지 SmartScreen 경고가 남을 수 있습니다. EV 인증서는 즉시 신뢰됩니다.
