/**
 * 파일 위치: launcher/RemoteHelp.App/Services/Engine/RelayClient.cs
 * 역할: 중계 서버와의 웹소켓 연결 (에이전트 역할)
 */
using System.Net.WebSockets;
using System.Text;
using System.Text.Json;

namespace RemoteHelp.Services.Engine;

public sealed class RelayClient : IAsyncDisposable
{
    private readonly Uri _uri;
    private readonly SemaphoreSlim _sendLock = new(1, 1);

    private ClientWebSocket? _socket;

    public RelayClient(string wsUrl, string token)
    {
        var separator = wsUrl.Contains('?') ? "&" : "?";
        _uri = new Uri(wsUrl + separator + "token=" + Uri.EscapeDataString(token));
    }

    public bool IsOpen => _socket?.State == WebSocketState.Open;

    /// <summary>상대(상담원 뷰어)가 붙었는지</summary>
    public bool PeerConnected { get; private set; }

    public event Action<ViewerCommand>? CommandReceived;
    public event Action<bool>? PeerStateChanged;
    public event Action<string>? Closed;

    public async Task ConnectAsync(CancellationToken ct)
    {
        _socket = new ClientWebSocket();
        _socket.Options.KeepAliveInterval = TimeSpan.FromSeconds(30);

        await _socket.ConnectAsync(_uri, ct);
        AppLogger.Info("중계 서버 연결됨");
    }

    /// <summary>수신 루프. 연결이 닫힐 때까지 돌아간다.</summary>
    public async Task ReceiveLoopAsync(CancellationToken ct)
    {
        var buffer = new byte[64 * 1024];

        while (_socket is { State: WebSocketState.Open } && !ct.IsCancellationRequested)
        {
            using var message = new MemoryStream();
            WebSocketReceiveResult result;

            do
            {
                result = await _socket.ReceiveAsync(new ArraySegment<byte>(buffer), ct);

                if (result.MessageType == WebSocketMessageType.Close)
                {
                    var reason = result.CloseStatusDescription ?? "closed";
                    AppLogger.Info($"중계 서버 연결 종료: {(int?)result.CloseStatus} {reason}");
                    Closed?.Invoke(reason);
                    return;
                }

                message.Write(buffer, 0, result.Count);
            }
            while (!result.EndOfMessage);

            if (result.MessageType == WebSocketMessageType.Text)
            {
                HandleControl(Encoding.UTF8.GetString(message.ToArray()));
                continue;
            }

            var command = ProtocolReader.Parse(message.ToArray());

            if (command.HasValue)
            {
                CommandReceived?.Invoke(command.Value);
            }
        }
    }

    private void HandleControl(string json)
    {
        try
        {
            using var doc = JsonDocument.Parse(json);

            if (!doc.RootElement.TryGetProperty("type", out var typeElement))
            {
                return;
            }

            switch (typeElement.GetString())
            {
                case "ready":
                    AppLogger.Info("중계 서버 세션 등록 완료");
                    break;

                case "peer_connected":
                    PeerConnected = true;
                    AppLogger.Info("상담원이 접속했습니다.");
                    PeerStateChanged?.Invoke(true);
                    break;

                case "peer_disconnected":
                    PeerConnected = false;
                    AppLogger.Info("상담원 연결이 끊어졌습니다.");
                    PeerStateChanged?.Invoke(false);
                    break;

                case "error":
                    var message = doc.RootElement.TryGetProperty("message", out var m)
                        ? m.GetString() : "중계 서버 오류";
                    AppLogger.Error("중계 서버 오류: " + message);
                    break;
            }
        }
        catch (JsonException ex)
        {
            AppLogger.Warn("제어 메시지 해석 실패: " + ex.Message);
        }
    }

    /// <summary>
    /// 바이너리 프레임을 보낸다. 웹소켓은 동시 전송을 허용하지 않으므로 직렬화한다.
    /// </summary>
    public async Task SendAsync(byte[] frame, CancellationToken ct)
    {
        if (_socket is not { State: WebSocketState.Open })
        {
            return;
        }

        await _sendLock.WaitAsync(ct);

        try
        {
            await _socket.SendAsync(frame, WebSocketMessageType.Binary, true, ct);
        }
        catch (Exception ex) when (ex is WebSocketException or ObjectDisposedException)
        {
            AppLogger.Warn("프레임 전송 실패: " + ex.Message);
        }
        finally
        {
            _sendLock.Release();
        }
    }

    public async ValueTask DisposeAsync()
    {
        if (_socket != null)
        {
            try
            {
                if (_socket.State == WebSocketState.Open)
                {
                    using var timeout = new CancellationTokenSource(TimeSpan.FromSeconds(2));
                    await _socket.CloseAsync(WebSocketCloseStatus.NormalClosure, "bye", timeout.Token);
                }
            }
            catch (Exception ex)
            {
                AppLogger.Warn("웹소켓 종료 중 오류: " + ex.Message);
            }

            _socket.Dispose();
            _socket = null;
        }

        _sendLock.Dispose();
    }
}
