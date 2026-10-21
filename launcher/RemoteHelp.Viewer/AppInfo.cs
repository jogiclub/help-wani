/**
 * 파일 위치: launcher/RemoteHelp.Viewer/AppInfo.cs
 * 역할: 뷰어가 띄울 포털 주소와 시험 서버 설정
 */
namespace RemoteHelp.Viewer;

public static class AppInfo
{
    public const string ProductName = "RemoteHelp 상담원 뷰어";

    /// <summary>
    /// 포털 주소. 배포 빌드에서는 운영 주소(https, 끝에 /)로 교체한다.
    /// 개발 중에는 REMOTEHELP_PORTAL 환경변수로 바꿀 수 있다.
    /// </summary>
#if TEST_BUILD
    public const string DefaultPortal = "http://118.36.197.219:8099/";

    /// <summary>시험 서버의 자체 서명 인증서를 허용할 호스트. 시험 빌드에만 존재한다.</summary>
    public const string TestTrustedHost = "118.36.197.219";
#else
    public const string DefaultPortal = "https://portal.example.com/";
#endif

    public static string PortalBaseUrl =>
        Environment.GetEnvironmentVariable("REMOTEHELP_PORTAL") ?? DefaultPortal;

    /// <summary>상담원 콘솔 시작 주소. 로그인하지 않았으면 포털이 로그인 화면으로 보낸다.</summary>
    public static string StartUrl => PortalBaseUrl.TrimEnd('/') + "/console";
}
