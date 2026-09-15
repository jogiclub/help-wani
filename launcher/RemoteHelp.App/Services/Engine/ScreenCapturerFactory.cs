/**
 * 파일 위치: launcher/RemoteHelp.App/Services/Engine/ScreenCapturerFactory.cs
 * 역할: 환경에 맞는 캡처 구현을 고른다 (DXGI 우선 시도, 실패하면 GDI)
 */
namespace RemoteHelp.Services.Engine;

public static class ScreenCapturerFactory
{
    /// <summary>
    /// 환경변수 REMOTEHELP_CAPTURE 로 강제할 수 있다. (gdi / dxgi / auto)
    /// </summary>
    public static IScreenCapturer Create()
    {
        var mode = (Environment.GetEnvironmentVariable("REMOTEHELP_CAPTURE") ?? "auto").ToLowerInvariant();

        if (mode == "gdi")
        {
            AppLogger.Info("캡처 방식 강제: GDI");
            return new GdiScreenCapturer();
        }

        if (mode is "auto" or "dxgi")
        {
            var dxgi = DxgiScreenCapturer.TryCreate();

            if (dxgi != null)
            {
                return dxgi;
            }

            if (mode == "dxgi")
            {
                AppLogger.Warn("DXGI 를 강제했지만 사용할 수 없어 GDI 로 전환합니다.");
            }
        }

        AppLogger.Info("캡처 방식: GDI");
        return new GdiScreenCapturer();
    }
}
