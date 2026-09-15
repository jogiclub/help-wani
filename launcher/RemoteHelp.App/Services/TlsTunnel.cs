/**
 * 파일 위치: launcher/RemoteHelp.App/Services/TlsTunnel.cs
 * 역할: 127.0.0.1 임의 포트 -> 중계 서버 TLS(5501) 터널
 *
 * 주의: TLS 1.3 에서는 핸드셰이크 직후 NewSessionTicket 레코드가 도착할 수 있다.
 *       읽기 타임아웃을 걸고 폴링하면 애플리케이션 데이터가 없는 상태에서 타임아웃이 발생해
 *       연결이 끊긴 것처럼 보인다. 따라서 타임아웃 없이 방향별 비동기 복사를 사용한다.
 *       (docs/spike-report.md S-5 참조)
 */
using System.Net;
using System.Net.Security;
using System.Net.Sockets;
using System.Security.Authentication;

namespace RemoteHelp.Services;

public sealed class TlsTunnel : IAsyncDisposable
{
    private readonly string _relayHost;
    private readonly int _relayPort;
    private readonly CancellationTokenSource _cts = new();

    private TcpListener? _listener;
    private Task? _acceptTask;

    public TlsTunnel(string relayHost, int relayPort)
    {
        _relayHost = relayHost;
        _relayPort = relayPort;
    }

    /// <summary>winvnc 가 접속할 로컬 포트</summary>
    public int LocalPort { get; private set; }

    /// <summary>중계 서버와의 연결이 끊어졌을 때 발생</summary>
    public event Action? TunnelClosed;

    public void Start()
    {
        // 포트 0 으로 바인딩하면 OS 가 사용 가능한 포트를 배정한다.
        _listener = new TcpListener(IPAddress.Loopback, 0);
        _listener.Start();
        LocalPort = ((IPEndPoint)_listener.LocalEndpoint).Port;

        AppLogger.Info($"TLS 터널 대기: 127.0.0.1:{LocalPort} -> {_relayHost}:{_relayPort}");

        _acceptTask = Task.Run(() => AcceptLoopAsync(_cts.Token));
    }

    private async Task AcceptLoopAsync(CancellationToken ct)
    {
        try
        {
            // winvnc 는 세션당 한 번 접속한다. 재접속(-autoreconnect)에 대비해 반복 수락한다.
            while (!ct.IsCancellationRequested)
            {
                var local = await _listener!.AcceptTcpClientAsync(ct);
                _ = Task.Run(() => BridgeAsync(local, ct), ct);
            }
        }
        catch (OperationCanceledException)
        {
        }
        catch (Exception ex)
        {
            AppLogger.Error("터널 수락 루프 오류", ex);
        }
    }

    private async Task BridgeAsync(TcpClient local, CancellationToken ct)
    {
        using (local)
        {
            try
            {
                local.NoDelay = true;

                using var remote = new TcpClient { NoDelay = true };
                await remote.ConnectAsync(_relayHost, _relayPort, ct);

                await using var ssl = new SslStream(remote.GetStream(), leaveInnerStreamOpen: false);

                // 서버 인증서 검증은 반드시 수행한다(기본 콜백 사용, 우회 금지).
                await ssl.AuthenticateAsClientAsync(new SslClientAuthenticationOptions
                {
                    TargetHost = _relayHost,
                    EnabledSslProtocols = SslProtocols.Tls12 | SslProtocols.Tls13,
                }, ct);

                AppLogger.Info($"중계 서버 TLS 연결됨 ({ssl.SslProtocol})");

                var localStream = local.GetStream();

                var upstream = localStream.CopyToAsync(ssl, ct);
                var downstream = ssl.CopyToAsync(localStream, ct);

                await Task.WhenAny(upstream, downstream);
            }
            catch (OperationCanceledException)
            {
            }
            catch (AuthenticationException ex)
            {
                AppLogger.Error("중계 서버 인증서 검증 실패", ex);
            }
            catch (Exception ex)
            {
                AppLogger.Error("터널 중계 오류", ex);
            }
            finally
            {
                if (!ct.IsCancellationRequested)
                {
                    TunnelClosed?.Invoke();
                }
            }
        }
    }

    public async ValueTask DisposeAsync()
    {
        try
        {
            await _cts.CancelAsync();
            _listener?.Stop();

            if (_acceptTask != null)
            {
                await Task.WhenAny(_acceptTask, Task.Delay(2000));
            }
        }
        catch (Exception ex)
        {
            AppLogger.Warn("터널 종료 중 오류: " + ex.Message);
        }
        finally
        {
            _cts.Dispose();
            AppLogger.Info("TLS 터널 종료");
        }
    }
}
