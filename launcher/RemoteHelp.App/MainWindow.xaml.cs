/**
 * 파일 위치: launcher/RemoteHelp.App/MainWindow.xaml.cs
 * 역할: 화면 전환과 사용자 동작 처리
 */
using System.Diagnostics;
using System.Text.RegularExpressions;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Input;
using System.Windows.Threading;
using RemoteHelp.Services;
using RemoteHelp.Views;

namespace RemoteHelp;

public partial class MainWindow : Window
{
    private readonly SessionManager _session = new();
    private readonly DispatcherTimer _elapsedTimer = new() { Interval = TimeSpan.FromSeconds(1) };

    private OverlayWindow? _overlay;
    private TrayIconHost? _tray;
    private bool _closingByCode;

    public MainWindow()
    {
        InitializeComponent();

        _session.SessionTerminated += OnSessionTerminated;
        _elapsedTimer.Tick += (_, _) => UpdateElapsed();

        Loaded += OnLoaded;
        Closing += OnClosing;
    }

    private async void OnLoaded(object sender, RoutedEventArgs e)
    {
        TxtCode.Focus();

        if (App.LaunchOrgCode != null)
        {
            TxtOrgName.Text = $"{App.LaunchOrgCode} 고객센터 연결";
        }

        _tray = new TrayIconHost(OnTrayEndRequested);

        await CheckVersionAsync();
    }

    /// <summary>최소 지원 버전 미만이면 스토어 업데이트를 안내한다.</summary>
    private async Task CheckVersionAsync()
    {
        var version = await _session.CheckVersionAsync();

        if (version == null)
        {
            return;
        }

        if (!Version.TryParse(version.MinVersion, out var min) ||
            !Version.TryParse(AppInfo.Version, out var current))
        {
            return;
        }

        if (current >= min)
        {
            return;
        }

        var result = MessageBox.Show(
            $"새 버전이 필요합니다. (현재 {current}, 최소 {min})\nMicrosoft Store 에서 업데이트하시겠습니까?",
            AppInfo.ProductName, MessageBoxButton.OKCancel, MessageBoxImage.Information);

        if (result == MessageBoxResult.OK && !string.IsNullOrEmpty(version.StoreUrl))
        {
            OpenUrl(version.StoreUrl);
        }

        BtnConnect.IsEnabled = false;
        TxtStartMessage.Text = "업데이트 후 다시 실행해 주세요.";
    }

    // ------------------------------------------------------------------
    // 1. 시작 화면
    // ------------------------------------------------------------------

    private void TxtCode_PreviewTextInput(object sender, TextCompositionEventArgs e)
    {
        // 숫자만 입력받는다.
        e.Handled = !Regex.IsMatch(e.Text, "^[0-9]+$");
    }

    private async void BtnConnect_Click(object sender, RoutedEventArgs e)
    {
        TxtStartMessage.Text = string.Empty;

        var code = TxtCode.Text.Trim();

        if (code.Length != 6)
        {
            TxtStartMessage.Text = "6자리 숫자 코드를 입력해 주세요.";
            return;
        }

        if (ChkConsent.IsChecked != true)
        {
            TxtStartMessage.Text = "개인정보 처리 및 원격 접속에 동의해 주세요.";
            return;
        }

        BtnConnect.IsEnabled = false;

        try
        {
            var result = await _session.VerifyAsync(code);

            TxtConfirmMessage.Text = $"{result.OrgName}의 상담원이 내 PC 에 접속합니다.\n허용하시겠습니까?";
            ShowPanel(PanelConfirm);
        }
        catch (PortalApiException ex)
        {
            TxtStartMessage.Text = ex.Message;
        }
        finally
        {
            BtnConnect.IsEnabled = true;
        }
    }

    // ------------------------------------------------------------------
    // 2. 확인 화면
    // ------------------------------------------------------------------

    private async void BtnAllow_Click(object sender, RoutedEventArgs e)
    {
        BtnAllow.IsEnabled = false;
        TxtConnState.Text = (string)FindResource("Str.Connecting");
        ShowPanel(PanelConnected);

        try
        {
            await _session.ConnectAsync();

            TxtConnState.Text = "상담원 연결을 기다리는 중입니다.";
            _elapsedTimer.Start();

            _overlay = new OverlayWindow(OnOverlayEndRequested);
            _overlay.Show();
        }
        catch (Exception ex)
        {
            AppLogger.Error("연결 준비 실패", ex);
            MessageBox.Show("원격지원을 시작하지 못했습니다.\n" + ex.Message,
                AppInfo.ProductName, MessageBoxButton.OK, MessageBoxImage.Error);

            await EndSessionAsync("start_failed");
        }
        finally
        {
            BtnAllow.IsEnabled = true;
        }
    }

    private void BtnCancelConfirm_Click(object sender, RoutedEventArgs e)
    {
        ShowPanel(PanelStart);
        TxtCode.Clear();
        TxtCode.Focus();
    }

    // ------------------------------------------------------------------
    // 3. 연결 중 화면
    // ------------------------------------------------------------------

    private void UpdateElapsed()
    {
        if (_session.StartedAt == null)
        {
            return;
        }

        var elapsed = DateTime.Now - _session.StartedAt.Value;
        TxtElapsed.Text = $"경과 시간 {elapsed:mm\\:ss}";
    }

    private async void BtnEnd_Click(object sender, RoutedEventArgs e) => await ConfirmEndAsync();

    private async void OnOverlayEndRequested() => await ConfirmEndAsync();

    private async void OnTrayEndRequested() => await ConfirmEndAsync();

    private async Task ConfirmEndAsync()
    {
        if (_session.State != SessionState.Connected)
        {
            return;
        }

        var result = MessageBox.Show("원격지원을 종료할까요?", AppInfo.ProductName,
            MessageBoxButton.YesNo, MessageBoxImage.Question);

        if (result == MessageBoxResult.Yes)
        {
            await EndSessionAsync("customer_ended");
        }
    }

    private void OnSessionTerminated(string reason)
    {
        Dispatcher.Invoke(async () =>
        {
            await EndSessionAsync(reason);
        });
    }

    // ------------------------------------------------------------------
    // 4. 종료 화면
    // ------------------------------------------------------------------

    private async Task EndSessionAsync(string reason)
    {
        _elapsedTimer.Stop();

        _overlay?.CloseOverlay();
        _overlay = null;

        await _session.EndAsync(reason);

        TxtEndDetail.Text = reason switch
        {
            "agent_ended" => "상담원이 원격지원을 종료했습니다.",
            "customer_ended" => "고객님이 원격지원을 종료했습니다.",
            "engine_exited" => "원격지원 엔진이 종료되었습니다.",
            _ => string.Empty,
        };

        BtnSurvey.Visibility = string.IsNullOrEmpty(_session.Session?.SurveyUrl)
            ? Visibility.Collapsed
            : Visibility.Visible;

        ShowPanel(PanelEnded);
    }

    private void BtnSurvey_Click(object sender, RoutedEventArgs e)
    {
        var url = _session.Session?.SurveyUrl;

        if (!string.IsNullOrEmpty(url))
        {
            OpenUrl(url);
        }
    }

    private void BtnClose_Click(object sender, RoutedEventArgs e)
    {
        _closingByCode = true;
        Close();
    }

    // ------------------------------------------------------------------
    // 공통
    // ------------------------------------------------------------------

    private void ShowPanel(Panel target)
    {
        foreach (var panel in new Panel[] { PanelStart, PanelConfirm, PanelConnected, PanelEnded })
        {
            panel.Visibility = ReferenceEquals(panel, target) ? Visibility.Visible : Visibility.Collapsed;
        }
    }

    private static void OpenUrl(string url)
    {
        try
        {
            Process.Start(new ProcessStartInfo(url) { UseShellExecute = true });
        }
        catch (Exception ex)
        {
            AppLogger.Warn("링크 열기 실패: " + ex.Message);
        }
    }

    private async void OnClosing(object? sender, System.ComponentModel.CancelEventArgs e)
    {
        if (_session.State == SessionState.Connected && !_closingByCode)
        {
            var result = MessageBox.Show("원격지원이 진행 중입니다. 종료할까요?", AppInfo.ProductName,
                MessageBoxButton.YesNo, MessageBoxImage.Warning);

            if (result != MessageBoxResult.Yes)
            {
                e.Cancel = true;
                return;
            }
        }

        e.Cancel = true;   // 정리 작업을 마친 뒤 직접 종료한다.

        _overlay?.CloseOverlay();
        _tray?.Dispose();

        await _session.DisposeAsync();

        Application.Current.Shutdown();
    }
}
