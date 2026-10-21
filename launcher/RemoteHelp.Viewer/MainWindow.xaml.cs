/**
 * 파일 위치: launcher/RemoteHelp.Viewer/MainWindow.xaml.cs
 * 역할: WebView2 초기화, 로그인 상태 보존, 전체 화면 전환, 창 제목 동기화
 */
using System.Diagnostics;
using System.Windows;
using Microsoft.Web.WebView2.Core;

namespace RemoteHelp.Viewer;

public partial class MainWindow : Window
{
    private WindowState _stateBeforeFullscreen = WindowState.Normal;

    public MainWindow()
    {
        InitializeComponent();
        Loaded += async (_, _) => await InitializeBrowserAsync();
    }

    private async Task InitializeBrowserAsync()
    {
        try
        {
            // 로그인 상태가 유지되도록 사용자 데이터 폴더를 고정한다.
            var dataFolder = Path.Combine(
                Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
                "RemoteHelpViewer", "WebView2");

            var environment = await CoreWebView2Environment.CreateAsync(null, dataFolder);
            await Browser.EnsureCoreWebView2Async(environment);
        }
        catch (Exception ex)
        {
            MessageBox.Show(
                "WebView2 런타임을 시작하지 못했습니다.\n"
                + "Microsoft Edge WebView2 Runtime 이 설치되어 있는지 확인해 주세요.\n"
                + "https://developer.microsoft.com/microsoft-edge/webview2/\n\n" + ex.Message,
                AppInfo.ProductName, MessageBoxButton.OK, MessageBoxImage.Error);
            Close();
            return;
        }

        var core = Browser.CoreWebView2;

        core.DocumentTitleChanged += (_, _) =>
            Title = string.IsNullOrWhiteSpace(core.DocumentTitle)
                ? AppInfo.ProductName
                : $"{core.DocumentTitle} - {AppInfo.ProductName}";

        core.ContainsFullScreenElementChanged += (_, _) => SetFullscreen(core.ContainsFullScreenElement);

        // 새 창 요청(target=_blank 등)은 같은 창에서 연다. 외부 사이트는 기본 브라우저로 보낸다.
        core.NewWindowRequested += (_, e) =>
        {
            e.Handled = true;

            if (Uri.TryCreate(e.Uri, UriKind.Absolute, out var uri) && IsPortal(uri))
            {
                core.Navigate(e.Uri);
            }
            else
            {
                Process.Start(new ProcessStartInfo(e.Uri) { UseShellExecute = true });
            }
        };

#if TEST_BUILD
        // 시험 서버(자체 서명 인증서) 전용 예외. 이 호스트 외에는 정상 검증한다. 배포 빌드에는 포함되지 않는다.
        core.ServerCertificateErrorDetected += (_, e) =>
        {
            if (Uri.TryCreate(e.RequestUri, UriKind.Absolute, out var uri)
                && string.Equals(uri.Host, AppInfo.TestTrustedHost, StringComparison.Ordinal))
            {
                e.Action = CoreWebView2ServerCertificateErrorAction.AlwaysAllow;
            }
        };
#endif

        core.Navigate(AppInfo.StartUrl);
    }

    private static bool IsPortal(Uri uri)
    {
        return Uri.TryCreate(AppInfo.PortalBaseUrl, UriKind.Absolute, out var portal)
            && string.Equals(uri.Host, portal.Host, StringComparison.OrdinalIgnoreCase)
            && uri.Port == portal.Port;
    }

    private void SetFullscreen(bool on)
    {
        if (on)
        {
            _stateBeforeFullscreen = WindowState;
            WindowStyle = WindowStyle.None;
            WindowState = WindowState.Maximized;
        }
        else
        {
            WindowStyle = WindowStyle.SingleBorderWindow;
            WindowState = _stateBeforeFullscreen;
        }
    }
}
