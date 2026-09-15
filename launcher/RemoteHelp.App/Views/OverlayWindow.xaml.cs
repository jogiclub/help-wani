/**
 * 파일 위치: launcher/RemoteHelp.App/Views/OverlayWindow.xaml.cs
 * 역할: 상시 표시 알림 창의 위치 고정, 경과 시간 갱신, 종료 요청 전달
 */
using System.Windows;
using System.Windows.Threading;

namespace RemoteHelp.Views;

public partial class OverlayWindow : Window
{
    private readonly Action _onEndRequested;
    private readonly DispatcherTimer _timer = new() { Interval = TimeSpan.FromSeconds(1) };
    private readonly DateTime _startedAt = DateTime.Now;

    private bool _closingByCode;

    public OverlayWindow(Action onEndRequested)
    {
        InitializeComponent();

        _onEndRequested = onEndRequested;

        Loaded += (_, _) => MoveToTopRight();
        Closing += OnClosing;

        _timer.Tick += (_, _) =>
        {
            var elapsed = DateTime.Now - _startedAt;
            TxtElapsed.Text = $"경과 {elapsed:mm\\:ss}";
        };
        _timer.Start();
    }

    /// <summary>주 화면 오른쪽 위에 배치한다.</summary>
    private void MoveToTopRight()
    {
        var area = SystemParameters.WorkArea;
        Left = area.Right - Width - 16;
        Top = area.Top + 16;
    }

    private void BtnEnd_Click(object sender, RoutedEventArgs e) => _onEndRequested();

    /// <summary>사용자가 임의로 닫을 수 없게 한다. 종료는 런처가 결정한다.</summary>
    private void OnClosing(object? sender, System.ComponentModel.CancelEventArgs e)
    {
        if (!_closingByCode)
        {
            e.Cancel = true;
            _onEndRequested();
        }
    }

    public void CloseOverlay()
    {
        _closingByCode = true;
        _timer.Stop();
        Close();
    }
}
