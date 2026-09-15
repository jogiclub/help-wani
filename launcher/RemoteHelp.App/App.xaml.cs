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
    /// <summary>remotehelp://connect?org=... 로 전달된 조직 코드</summary>
    public static string? LaunchOrgCode { get; private set; }

    protected override void OnStartup(StartupEventArgs e)
    {
        base.OnStartup(e);

        AppLogger.Initialize();
        AppLogger.Info("런처 시작");

        LaunchOrgCode = ProtocolArguments.ParseOrgCode(e.Args);

        if (LaunchOrgCode != null)
        {
            AppLogger.Info($"프로토콜 실행: org={LaunchOrgCode}");
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
