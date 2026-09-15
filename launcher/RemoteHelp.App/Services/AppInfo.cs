/**
 * 파일 위치: launcher/RemoteHelp.App/Services/AppInfo.cs
 * 역할: 앱 버전과 포털 주소 등 기본 설정값 제공
 */
using System.Reflection;

namespace RemoteHelp.Services;

public static class AppInfo
{
    public static string Version =>
        Assembly.GetExecutingAssembly().GetName().Version?.ToString(3) ?? "1.0.0";

    /// <summary>
    /// 포털 주소. 배포 빌드에서는 appsettings 나 빌드 상수로 고정한다.
    /// 개발 중에는 REMOTEHELP_PORTAL 환경변수로 바꿀 수 있다.
    /// </summary>
    public static string PortalBaseUrl =>
        Environment.GetEnvironmentVariable("REMOTEHELP_PORTAL") ?? "https://portal.example.com/";

    public const string ProductName = "RemoteHelp";
}
