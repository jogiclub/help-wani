/**
 * 파일 위치: launcher/RemoteHelp.App/Services/SessionManager.cs
 * 역할: 세션 전체 수명 관리 (코드 검증 -> 터널 -> winvnc -> 하트비트 -> 정리)
 */
namespace RemoteHelp.Services;

public enum SessionState
{
    Idle,
    Verifying,
    Confirming,
    Connecting,
    Connected,
    Ended,
}

public sealed class SessionManager : IAsyncDisposable
{
    private readonly PortalApiClient _api = new(AppInfo.PortalBaseUrl);
    private readonly CancellationTokenSource _cts = new();

    private SessionWorkspace? _workspace;
    private TlsTunnel? _tunnel;
    private VncRunner? _vnc;
    private Task? _heartbeatTask;
    private bool _cleaned;

    public VerifyResult? Session { get; private set; }

    public SessionState State { get; private set; } = SessionState.Idle;

    public DateTime? StartedAt { get; private set; }

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

    /// <summary>고객이 허용을 누른 뒤 실제 원격 연결을 준비한다.</summary>
    public async Task ConnectAsync()
    {
        if (Session == null)
        {
            throw new InvalidOperationException("검증된 세션이 없습니다.");
        }

        SetState(SessionState.Connecting);

        _workspace = SessionWorkspace.Create();

        _vnc = new VncRunner(_workspace);
        _vnc.WriteIni(Session.VncPassword);

        _tunnel = new TlsTunnel(Session.RelayHost, Session.RelayPort);
        _tunnel.TunnelClosed += OnTunnelClosed;
        _tunnel.Start();

        _vnc.Start(Session.RepeaterId, _tunnel.LocalPort);

        await _api.ReportStatusAsync(Session.SessionId, Session.LauncherSecret, "waiting", null, _cts.Token);

        StartedAt = DateTime.Now;
        SetState(SessionState.Connected);

        _heartbeatTask = Task.Run(() => HeartbeatLoopAsync(_cts.Token));
    }

    private void OnTunnelClosed()
    {
        AppLogger.Warn("중계 서버와의 연결이 끊어졌습니다.");
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

                // winvnc 가 죽었으면 세션을 유지할 이유가 없다.
                if (_vnc is { IsRunning: false })
                {
                    AppLogger.Warn("원격지원 엔진이 종료되어 세션을 끝냅니다.");
                    SessionTerminated?.Invoke("engine_exited");
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

    /// <summary>
    /// 어떤 경로로 끝나든 반드시 호출한다.
    /// winvnc 종료 -> 터널 종료 -> 작업 폴더 삭제 -> 서버에 종료 보고 순서로 정리한다.
    /// </summary>
    public async Task EndAsync(string reason)
    {
        if (_cleaned)
        {
            return;
        }

        _cleaned = true;
        AppLogger.Info("세션 종료 처리 시작: " + reason);

        _vnc?.Stop();
        _vnc?.Dispose();
        _vnc = null;

        if (_tunnel != null)
        {
            _tunnel.TunnelClosed -= OnTunnelClosed;
            await _tunnel.DisposeAsync();
            _tunnel = null;
        }

        _workspace?.Dispose();
        _workspace = null;

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
