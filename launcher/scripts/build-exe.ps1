<#
    파일 위치: launcher/scripts/build-exe.ps1
    역할: 직접 배포용 단일 실행 파일(RemoteHelp.exe)을 빌드한다.

    Microsoft Store 배포(MSIX)와 별개 경로다. 이 파일은 고객 접속 페이지에서 바로 내려받는다.

    사용 예:
      .\build-exe.ps1
      .\build-exe.ps1 -SignPfx 인증서.pfx -SignPassword 비밀번호
      .\build-exe.ps1 -OutDir ..\..\portal\assets\downloads
      .\build-exe.ps1 -TestBuild

    -TestBuild : 시험 서버용 빌드. TEST_BUILD 상수를 켜서 시험 포털 주소 고정과
                 시험 중계 서버 인증서 예외(AppInfo.cs, RelayClient.cs)를 포함한다.
                 시험 서버(http://118.36.197.219:8099/demo/download) 전용이며
                 서명과 함께 쓸 수 없고, 운영 고객에게 배포하면 안 된다.

    산출물은 항상 -OutDir(기본 portal\assets\downloads)에 RemoteHelp.exe 로 복사한다.
    시험 서버는 이 파일을 RemoteHelp_<조직코드>.exe 로 내려주므로 빌드가 끝나면 곧 배포된 상태가 된다.
#>
[CmdletBinding()]
param(
    [string]$Configuration = "Release",
    [string]$OutDir,
    [string]$SignPfx,
    [string]$SignPassword,
    [string]$TimestampUrl = "http://timestamp.digicert.com",
    [switch]$TestBuild
)

$ErrorActionPreference = "Stop"

if ($TestBuild -and $SignPfx) {
    throw "-TestBuild 는 시험 전용입니다. 서명해서 배포할 수 없으니 -SignPfx 와 함께 쓸 수 없습니다."
}

if (-not $OutDir) {
    $OutDir = "$PSScriptRoot\..\..\portal\assets\downloads"
}

function Write-Step($message) {
    Write-Host ""
    Write-Host "== $message" -ForegroundColor Cyan
}

$project = Resolve-Path "$PSScriptRoot\..\RemoteHelp.App\RemoteHelp.App.csproj"
$publishDir = "$PSScriptRoot\..\RemoteHelp.App\bin\$Configuration\publish"
$defineArgs = @()

if ($TestBuild) {
    # 배포 빌드와 산출물이 섞이지 않도록 별도 폴더를 쓴다.
    $publishDir = "$PSScriptRoot\..\RemoteHelp.App\bin\$Configuration\publish-test"
    $defineArgs = @("-p:DefineConstants=TEST_BUILD")
    Write-Host "시험 빌드입니다. 인증서 예외와 시험 서버 주소가 포함되므로 고객에게 배포하지 마세요." -ForegroundColor Yellow
}

Write-Step "1. 단일 실행 파일 빌드"
# 자체 포함(SelfContained)으로 만들면 .NET 런타임 설치 없이 실행된다.
# 파일이 커지지만(약 70~90MB) 고객이 런타임을 따로 설치할 필요가 없다.
dotnet publish $project `
    -c $Configuration `
    -r win-x64 `
    --self-contained true `
    -p:PublishSingleFile=true `
    -p:IncludeNativeLibrariesForSelfExtract=true `
    -p:EnableCompressionInSingleFile=true `
    -p:DebugType=none `
    @defineArgs `
    -o $publishDir

if ($LASTEXITCODE -ne 0) { throw "빌드에 실패했습니다." }

$exe = Join-Path $publishDir "RemoteHelp.exe"
if (-not (Test-Path $exe)) { throw "산출물을 찾을 수 없습니다: $exe" }

$sizeMb = [math]::Round((Get-Item $exe).Length / 1MB, 1)
Write-Host "산출물: $exe ($sizeMb MB)"

if ($SignPfx) {
    Write-Step "2. 코드 서명"
    $signtool = Get-ChildItem "${env:ProgramFiles(x86)}\Windows Kits\10\bin" -Recurse -Filter signtool.exe -ErrorAction SilentlyContinue |
        Where-Object { $_.FullName -match "x64" } | Select-Object -First 1

    if (-not $signtool) { throw "signtool.exe 를 찾을 수 없습니다. Windows SDK 를 설치해 주세요." }

    & $signtool.FullName sign /fd SHA256 /tr $TimestampUrl /td SHA256 `
        /f $SignPfx /p $SignPassword $exe

    if ($LASTEXITCODE -ne 0) { throw "코드 서명에 실패했습니다." }
    Write-Host "서명 완료"
} else {
    Write-Step "2. 코드 서명 건너뜀"
    Write-Host "서명하지 않은 파일은 SmartScreen 경고가 뜨고 일부 백신이 차단합니다." -ForegroundColor Yellow
    Write-Host "배포 전에 -SignPfx 옵션으로 서명해 주세요." -ForegroundColor Yellow
}

Write-Step "3. 출력 폴더로 복사"
New-Item -ItemType Directory -Force -Path $OutDir | Out-Null
$target = Join-Path $OutDir "RemoteHelp.exe"
Copy-Item $exe $target -Force

$hash = (Get-FileHash $target -Algorithm SHA256).Hash.ToLower()
Write-Host "복사 완료: $target"
Write-Host "SHA-256 : $hash"

if ($TestBuild) {
    Write-Step "다음 할 일 (시험 빌드)"
    Write-Host " - 시험 서버에 배포됨: http://118.36.197.219:8099/demo/download"
    Write-Host " - 내려받은 RemoteHelp_demo.exe 를 실행해 확인. 운영 고객에게는 배포하지 마세요."
    return
}

Write-Step "다음 할 일"
Write-Host " - 포털 고객 페이지에서 다운로드 버튼이 보이는지 확인: https://<도메인>/<조직코드>"
Write-Host " - 내려받은 파일 이름이 RemoteHelp_<조직코드>.exe 인지 확인"
Write-Host " - 그 파일을 실행하면 시작 화면에 조직 이름이 표시되는지 확인"
