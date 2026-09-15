/**
 * 파일 위치: launcher/RemoteHelp.App/Services/SessionManager.cs
 * 역할: 세션 전체 수명 관리 (코드 검증 -> 원격제어 엔진 시작 -> 하트비트 -> 정리)
 */
using RemoteHelp.Services.Engine;

namespace RemoteHelp.Services;

public enum SessionState
{
    Idle,
    Verifying,
    Confirming,
    Connecting,
    Waiting,
    Connected,
    Ended,
}

public sealed class SessionManager : IAsyncDisposable
{
    private readonly PortalApiClient _api = new(AppInfo.PortalBaseUrl);
    private readonly CancellationTokenSource _cts = new();

    private RemoteSession? _remote;
    private Task? _heartbeatTask;
    private bool _cleaned;

    public VerifyResult? Session { get; private set; }

    public SessionState State { get; private set; } = SessionState.Idle;

    public DateTime? StartedAt { get; private set; }

    /// <summary>현재 사용 중인 화면 캡처 방식 (GDI / DXGI)</summary>
    public string CaptureMethod => _remote?.CaptureMethod ?? "-";

    /// <summary>상태가 바뀔 때 발생 (UI 갱신용)</summary>
    public event Action<SessionState>? StateChanged;

    /// <summary>상담원 또는 서버가 세션을 끝냈을 때 발생</summary>
    public event Action<string>? SessionTerminated;

    /// <summary>코드를 검증한다. 성공하면 확인 화면으로 넘어간다.</summary>
    public async Task<VerifyResult> VerifyAsync(string code)
    {
        SetState(SessionState.Verifying);

        try
        {
            Session = await _api.VerifyCodeAsync(code, _cts.Token);
            SetState(SessionState.Confirming);
            AppLogger.Info($"코드 검증 성공: session_id={Session.SessionId}");
            return Session;
        }
        catch
        {
            SetState(SessionState.Idle);
            throw;
        }
    }

    /// <summary>고객이 허용을 누른 뒤 원격제어 엔진을 시작한다.</summary>
    public async Task ConnectAsync()
    {
        if (Session == null)
        {
            throw new InvalidOperationException("검증된 세션이 없습니다.");
        }

        SetState(SessionState.Connecting);

        _remote = new RemoteSession(Session.RelayWsUrl, Session.AgentToken);
        _remote.ViewerStateChanged += OnViewerStateChanged;
        _remote.Closed += OnRelayClosed;

        await _remote.StartAsync();

        await _api.ReportStatusAsync(Session.SessionId, Session.LauncherSecret, "waiting", null, _cts.Token);

        StartedAt = DateTime.Now;
        SetState(SessionState.Waiting);

        _heartbeatTask = Task.Run(() => HeartbeatLoopAsync(_cts.Token));
    }

    private void OnViewerStateChanged(bool connected)
    {
        if (connected)
        {
            SetState(SessionState.Connected);

            if (Session != null)
            {
                _ = _api.ReportStatusAsync(Session.SessionId, Session.LauncherSecret, "connected", null, _cts.Token);
            }
        }
        else if (State == SessionState.Connected)
        {
            SetState(SessionState.Waiting);
        }
    }

    private void OnRelayClosed(string reason)
    {
        AppLogger.Warn("중계 연결이 닫혔습니다: " + reason);

        // 상담원이 끝냈거나 세션이 만료된 경우다. 하트비트가 곧 확인한다.
    }

    private async Task HeartbeatLoopAsync(CancellationToken ct)
    {
        var interval = TimeSpan.FromSeconds(Math.Max(10, Session?.HeartbeatSec ?? 30));

        while (!ct.IsCancellationRequested)
        {
            try
            {
                await Task.Delay(interval, ct);

                if (Session == null)
                {
                    return;
                }

                var result = await _api.HeartbeatAsync(Session.SessionId, Session.LauncherSecret, ct);

                if (result.Terminate)
                {
                    AppLogger.Info("서버로부터 종료 신호를 받았습니다: " + result.EndReason);
                    SessionTerminated?.Invoke(result.EndReason ?? "agent_ended");
                    return;
                }
            }
            catch (OperationCanceledException)
            {
                return;
            }
            catch (Exception ex)
            {
                AppLogger.Warn("하트비트 오류: " + ex.Message);
            }
        }
    }

    /// <summary>어떤 경로로 끝나든 반드시 호출한다.</summary>
    public async Task EndAsync(string reason)
    {
        if (_cleaned)
        {
            return;
        }

        _cleaned = true;
        AppLogger.Info("세션 종료 처리 시작: " + reason);

        if (_remote != null)
        {
            _remote.ViewerStateChanged -= OnViewerStateChanged;
            _remote.Closed -= OnRelayClosed;
            await _remote.DisposeAsync();
            _remote = null;
        }

        if (Session != null)
        {
            try
            {
                using var timeout = new CancellationTokenSource(TimeSpan.FromSeconds(5));
                await _api.ReportStatusAsync(Session.SessionId, Session.LauncherSecret, "ended", reason, timeout.Token);
            }
            catch (Exception ex)
            {
                AppLogger.Warn("종료 보고 실패: " + ex.Message);
            }
        }

        SetState(SessionState.Ended);
        AppLogger.Info("세션 종료 처리 완료");
    }

    public async Task<VersionResult?> CheckVersionAsync() => await _api.GetVersionAsync(_cts.Token);

    private void SetState(SessionState state)
    {
        State = state;
        StateChanged?.Invoke(state);
    }

    public async ValueTask DisposeAsync()
    {
        await EndAsync("launcher_exit");
        await _cts.CancelAsync();

        if (_heartbeatTask != null)
        {
            await Task.WhenAny(_heartbeatTask, Task.Delay(1000));
        }

        _cts.Dispose();
        _api.Dispose();
    }
}
