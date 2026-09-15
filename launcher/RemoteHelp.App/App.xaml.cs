/**
 * 파일 위치: launcher/RemoteHelp.App/App.xaml.cs
 * 역할: 앱 시작/종료 처리, 프로토콜 인자 해석, 이전 세션 잔여물 정리
 */
using System.IO;
using System.Windows;
using RemoteHelp.Services;

namespace RemoteHelp;

public partial class App : Application
{
    /// <summary>프로토콜 인자 또는 실행 파일 이름에서 얻은 조직 코드</summary>
    public static string? LaunchOrgCode { get; private set; }

    protected override void OnStartup(StartupEventArgs e)
    {
        base.OnStartup(e);

        AppLogger.Initialize();
        AppLogger.Info("런처 시작");

        LaunchOrgCode = ProtocolArguments.ResolveOrgCode(e.Args);

        if (LaunchOrgCode != null)
        {
            AppLogger.Info($"조직 코드 확인: org={LaunchOrgCode}");
        }

        DispatcherUnhandledException += (_, args) =>
        {
            AppLogger.Error("처리되지 않은 예외", args.Exception);
            MessageBox.Show("예기치 못한 오류가 발생했습니다. 프로그램을 종료합니다.",
                "RemoteHelp", MessageBoxButton.OK, MessageBoxImage.Error);
            args.Handled = true;
            Shutdown(1);
        };
    }

    protected override void OnExit(ExitEventArgs e)
    {
        AppLogger.Info("런처 종료");
        base.OnExit(e);
    }
}
