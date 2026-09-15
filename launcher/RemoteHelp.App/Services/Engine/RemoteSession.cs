/**
 * 파일 위치: launcher/RemoteHelp.App/Services/Engine/RemoteSession.cs
 * 역할: 화면 캡처 -> 변경 타일 인코딩 -> 전송 루프와 입력 주입을 묶는 원격제어 엔진
 */
using System.Diagnostics;
using System.Windows;

namespace RemoteHelp.Services.Engine;

public sealed class RemoteSession : IAsyncDisposable
{
    private readonly RelayClient _relay;
    private readonly InputInjector _input = new();
    private readonly CancellationTokenSource _cts = new();

    private IScreenCapturer? _capturer;
    private TileEncoder? _encoder;
    private Task? _captureTask;
    private Task? _receiveTask;

    private int _jpegQuality = 70;
    private int _maxFps = 10;
    private uint _sequence;
    private volatile bool _fullFrameRequested = true;
    private int _lastCursorX = -1;
    private int _lastCursorY = -1;

    public RemoteSession(string wsUrl, string agentToken)
    {
        _relay = new RelayClient(wsUrl, agentToken);
        _relay.CommandReceived += OnCommand;
        _relay.PeerStateChanged += OnPeerStateChanged;
        _relay.Closed += reason => Closed?.Invoke(reason);
    }

    /// <summary>상담원이 접속/해제했을 때</summary>
    public event Action<bool>? ViewerStateChanged;

    /// <summary>중계 연결이 끊겼을 때</summary>
    public event Action<string>? Closed;

    public bool ViewerConnected => _relay.PeerConnected;

    public string CaptureMethod => _capturer?.Name ?? "-";

    public async Task StartAsync()
    {
        await _relay.ConnectAsync(_cts.Token);

        _capturer = ScreenCapturerFactory.Create();
        _encoder = new TileEncoder(_capturer.Geometry.Width, _capturer.Geometry.Height);

        await _relay.SendAsync(ProtocolWriter.ScreenInfo(_capturer.Geometry), _cts.Token);

        _receiveTask = Task.Run(() => _relay.ReceiveLoopAsync(_cts.Token));
        _captureTask = Task.Run(() => CaptureLoopAsync(_cts.Token));

        AppLogger.Info($"원격제어 엔진 시작 (캡처 방식 {_capturer.Name})");
    }

    /// <summary>
    /// 상담원이 붙어 있는 동안만 화면을 캡처해 보낸다.
    /// 아무도 보고 있지 않으면 CPU 를 쓰지 않는다.
    /// </summary>
    private async Task CaptureLoopAsync(CancellationToken ct)
    {
        var stopwatch = new Stopwatch();

        while (!ct.IsCancellationRequested)
        {
            if (!_relay.PeerConnected || _capturer == null || _encoder == null)
            {
                await Task.Delay(200, ct);
                continue;
            }

            stopwatch.Restart();

            try
            {
                await SendFrameAsync(ct);
            }
            catch (OperationCanceledException)
            {
                return;
            }
            catch (Exception ex)
            {
                AppLogger.Error("화면 전송 오류", ex);
                await Task.Delay(500, ct);
                continue;
            }

            var budget = 1000 / Math.Clamp(_maxFps, 1, 30);
            var remaining = budget - (int)stopwatch.ElapsedMilliseconds;

            if (remaining > 0)
            {
                await Task.Delay(remaining, ct);
            }
        }
    }

    private async Task SendFrameAsync(CancellationToken ct)
    {
        var capturer = _capturer!;
        var previousGeometry = capturer.Geometry;

        if (!capturer.TryCapture(out var frame))
        {
            return;
        }

        // 해상도가 바뀌면 인코더를 다시 만들고 뷰어에 알린다.
        if (!previousGeometry.SameAs(capturer.Geometry))
        {
            _encoder = new TileEncoder(capturer.Geometry.Width, capturer.Geometry.Height);
            await _relay.SendAsync(ProtocolWriter.ScreenInfo(capturer.Geometry), ct);
            await _relay.SendAsync(ProtocolWriter.DisplayChanged(), ct);
            _fullFrameRequested = true;
            return;
        }

        if (_fullFrameRequested)
        {
            _fullFrameRequested = false;
            _encoder!.Invalidate();
        }

        var started = Environment.TickCount;
        var tiles = _encoder!.EncodeChangedTiles(frame, _jpegQuality);

        _sequence++;

        foreach (var tile in tiles)
        {
            await _relay.SendAsync(ProtocolWriter.Tile(_sequence, tile), ct);
        }

        await _relay.SendAsync(
            ProtocolWriter.FrameEnd(_sequence, tiles.Count, Environment.TickCount - started), ct);

        await SendCursorAsync(ct);
    }

    private async Task SendCursorAsync(CancellationToken ct)
    {
        var cursor = _capturer?.CursorPosition;

        if (cursor == null)
        {
            return;
        }

        if (cursor.Value.X == _lastCursorX && cursor.Value.Y == _lastCursorY)
        {
            return;
        }

        _lastCursorX = cursor.Value.X;
        _lastCursorY = cursor.Value.Y;

        await _relay.SendAsync(ProtocolWriter.CursorPos(cursor.Value.X, cursor.Value.Y), ct);
    }

    private void OnPeerStateChanged(bool connected)
    {
        if (connected)
        {
            // 뷰어는 자기가 붙기 전에 보낸 화면 정보를 받지 못한다.
            // 접속할 때마다 다시 보내고 전체 화면부터 전송한다.
            _fullFrameRequested = true;

            if (_capturer != null)
            {
                _ = _relay.SendAsync(ProtocolWriter.ScreenInfo(_capturer.Geometry), _cts.Token);
            }
        }

        ViewerStateChanged?.Invoke(connected);
    }

    private void OnCommand(ViewerCommand command)
    {
        try
        {
            switch (command.Type)
            {
                case FrameType.MouseMove:
                    _input.MoveMouse(command.X, command.Y);
                    break;

                case FrameType.MouseButton:
                    _input.MouseButton(command.X, command.Y, command.Button, command.Down);
                    break;

                case FrameType.MouseWheel:
                    _input.MouseWheelScroll(command.X, command.Y, command.WheelDelta);
                    break;

                case FrameType.Key:
                    _input.Key(command.VirtualKey, command.Down, command.Extended);
                    break;

                case FrameType.ClipboardSet:
                    SetClipboard(command.Text);
                    break;

                case FrameType.CtrlAltDel:
                    _input.SendCtrlAltDel();
                    break;

                case FrameType.SetQuality:
                    _jpegQuality = Math.Clamp(command.JpegQuality, 10, 95);
                    _maxFps = Math.Clamp(command.MaxFps, 1, 30);
                    AppLogger.Info($"화질 변경: 품질 {_jpegQuality}, 최대 {_maxFps}fps");
                    break;

                case FrameType.RequestFullFrame:
                    _fullFrameRequested = true;
                    break;

                case FrameType.Ping:
                    _ = _relay.SendAsync(ProtocolWriter.Pong(command.Echo), _cts.Token);
                    break;
            }
        }
        catch (Exception ex)
        {
            AppLogger.Error("명령 처리 오류", ex);
        }
    }

    /// <summary>클립보드는 STA 스레드에서만 다룰 수 있으므로 UI 스레드로 넘긴다.</summary>
    private static void SetClipboard(string? text)
    {
        if (string.IsNullOrEmpty(text))
        {
            return;
        }

        Application.Current?.Dispatcher.Invoke(() =>
        {
            try
            {
                Clipboard.SetText(text);
            }
            catch (Exception ex)
            {
                AppLogger.Warn("클립보드 설정 실패: " + ex.Message);
            }
        });
    }

    public async ValueTask DisposeAsync()
    {
        await _cts.CancelAsync();

        foreach (var task in new[] { _captureTask, _receiveTask })
        {
            if (task != null)
            {
                await Task.WhenAny(task, Task.Delay(2000));
            }
        }

        await _relay.DisposeAsync();
        _capturer?.Dispose();
        _cts.Dispose();

        AppLogger.Info("원격제어 엔진 종료");
    }
}
