<#
    파일 위치: launcher/scripts/sideload-test.ps1
    역할: 자체 서명 인증서로 MSIX 를 서명해 사이드로드 설치까지 수행 (스토어 설치와 동일 조건 확인용)

    사용 예:
      powershell -ExecutionPolicy Bypass -File sideload-test.ps1 -Build
      powershell -ExecutionPolicy Bypass -File sideload-test.ps1 -MsixPath ..\RemoteHelp.Package\AppPackages\...\RemoteHelp.msix
#>
[CmdletBinding()]
param(
    [switch]$Build,
    [string]$MsixPath,
    [string]$PublisherCN = "CN=CompanyName, O=CompanyName, C=KR",
    [string]$PfxPath = "$PSScriptRoot\RemoteHelp.Test.pfx",
    [string]$PfxPassword = "RemoteHelpTest!"
)

$ErrorActionPreference = "Stop"

function Write-Step($message) {
    Write-Host ""
    Write-Host "== $message" -ForegroundColor Cyan
}

# 관리자 권한 확인 (인증서 신뢰 등록과 패키지 설치에 필요)
$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
$principal = New-Object Security.Principal.WindowsPrincipal($identity)
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw "관리자 권한 PowerShell 에서 실행해 주세요."
}

Write-Step "1. 테스트용 자체 서명 인증서 준비"
$existing = Get-ChildItem Cert:\CurrentUser\My | Where-Object { $_.Subject -eq $PublisherCN }

if (-not $existing) {
    $existing = New-SelfSignedCertificate `
        -Type Custom -Subject $PublisherCN `
        -KeyUsage DigitalSignature -FriendlyName "RemoteHelp Sideload Test" `
        -CertStoreLocation "Cert:\CurrentUser\My" `
        -TextExtension @("2.5.29.37={text}1.3.6.1.5.5.7.3.3", "2.5.29.19={text}")
    Write-Host "인증서를 새로 만들었습니다: $($existing.Thumbprint)"
} else {
    Write-Host "기존 인증서를 사용합니다: $($existing.Thumbprint)"
}

$securePassword = ConvertTo-SecureString -String $PfxPassword -Force -AsPlainText
Export-PfxCertificate -Cert "Cert:\CurrentUser\My\$($existing.Thumbprint)" -FilePath $PfxPath -Password $securePassword | Out-Null

Write-Step "2. 인증서를 신뢰할 수 있는 루트에 등록"
Import-Certificate -FilePath (Export-Certificate -Cert "Cert:\CurrentUser\My\$($existing.Thumbprint)" -FilePath "$PSScriptRoot\RemoteHelp.Test.cer").FullName `
    -CertStoreLocation "Cert:\LocalMachine\TrustedPeople" | Out-Null

if ($Build) {
    Write-Step "3. MSIX 빌드"
    $solution = Join-Path $PSScriptRoot "..\RemoteHelp.sln"
    & msbuild $solution /p:Configuration=Release /p:Platform=x64 `
        /p:UapAppxPackageBuildMode=SideloadOnly /p:AppxBundle=Never `
        /p:AppxPackageSigningEnabled=true /p:PackageCertificateKeyFile=$PfxPath `
        /p:PackageCertificatePassword=$PfxPassword
    if ($LASTEXITCODE -ne 0) { throw "MSIX 빌드에 실패했습니다." }

    $MsixPath = Get-ChildItem -Path (Join-Path $PSScriptRoot "..\RemoteHelp.Package\AppPackages") `
        -Filter *.msix -Recurse | Sort-Object LastWriteTime -Descending | Select-Object -First 1 -ExpandProperty FullName
}

if (-not $MsixPath) {
    throw "-Build 를 쓰거나 -MsixPath 로 패키지 경로를 지정해 주세요."
}

Write-Step "4. 패키지 설치"
Write-Host "대상: $MsixPath"
Add-AppxPackage -Path $MsixPath -ForceUpdateFromAnyVersion

Write-Step "5. 설치 확인"
Get-AppxPackage -Name "*RemoteHelp*" | Format-List Name, PackageFullName, InstallLocation, Status

Write-Host ""
Write-Host "점검 항목" -ForegroundColor Yellow
Write-Host " - 시작 메뉴에서 실행되는지"
Write-Host " - remotehelp://connect?org=demo 를 실행하면 앱이 뜨는지 (start remotehelp://connect?org=demo)"
Write-Host " - 설치 폴더의 ultravnc\winvnc.exe 가 실행되는지 (S-6)"
Write-Host " - 제거: Get-AppxPackage *RemoteHelp* | Remove-AppxPackage"
