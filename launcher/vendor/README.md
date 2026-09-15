# UltraVNC 바이너리 배치 안내

라이선스와 저장소 크기 문제로 UltraVNC 실행 파일은 이 저장소에 포함하지 않습니다.
빌드 전에 아래 구조로 파일을 배치해 주세요.

```
launcher/vendor/ultravnc/
  winvnc.exe
  (winvnc 가 요구하는 DLL 일체)
```

배치 후 `RemoteHelp.App.csproj` 가 빌드 출력의 `ultravnc\` 폴더로 복사합니다.
런처는 `AppContext.BaseDirectory\ultravnc\winvnc.exe` 를 실행합니다.

## 준비 시 확인할 것

1. 포함하는 UltraVNC 버전을 기록하고, 같은 버전의 소스를 공개 위치에 올립니다(GPL-3.0 의무).
   기록 위치: `launcher/THIRD_PARTY_NOTICES.md`
2. x64 빌드를 사용합니다(패키지 플랫폼이 x64).
3. `setpasswd.exe` 는 포함하지 않습니다. 설정 경로가 ProgramData 로 고정되어 있어
   런처의 세션별 설정 방식과 맞지 않습니다(docs/spike-report.md S-3 참조).
