/**
 * 파일 위치: launcher/RemoteHelp.App/Services/PortalApiClient.cs
 * 역할: 포털 런처 API 호출 (코드 검증, 상태 보고, 하트비트, 버전 확인)
 */
using System.Net.Http;
using System.Net.Http.Json;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace RemoteHelp.Services;

public sealed class ApiResponse<T>
{
    [JsonPropertyName("result")] public bool Result { get; set; }
    [JsonPropertyName("message")] public string Message { get; set; } = string.Empty;
    [JsonPropertyName("data")] public T? Data { get; set; }
}

public sealed class VerifyResult
{
    [JsonPropertyName("session_id")] public long SessionId { get; set; }
    [JsonPropertyName("repeater_id")] public string RepeaterId { get; set; } = string.Empty;
    [JsonPropertyName("vnc_password")] public string VncPassword { get; set; } = string.Empty;
    [JsonPropertyName("relay_host")] public string RelayHost { get; set; } = string.Empty;
    [JsonPropertyName("relay_port")] public int RelayPort { get; set; }
    [JsonPropertyName("org_name")] public string OrgName { get; set; } = string.Empty;
    [JsonPropertyName("org_logo_url")] public string OrgLogoUrl { get; set; } = string.Empty;
    [JsonPropertyName("launcher_secret")] public string LauncherSecret { get; set; } = string.Empty;
    [JsonPropertyName("survey_url")] public string SurveyUrl { get; set; } = string.Empty;
    [JsonPropertyName("heartbeat_sec")] public int HeartbeatSec { get; set; } = 30;
}

public sealed class HeartbeatResult
{
    [JsonPropertyName("terminate")] public bool Terminate { get; set; }
    [JsonPropertyName("status")] public string Status { get; set; } = string.Empty;
    [JsonPropertyName("end_reason")] public string? EndReason { get; set; }
}

public sealed class VersionResult
{
    [JsonPropertyName("min_version")] public string MinVersion { get; set; } = "1.0.0";
    [JsonPropertyName("store_url")] public string StoreUrl { get; set; } = string.Empty;
}

/// <summary>API 호출 실패를 사용자에게 보여줄 메시지와 함께 전달한다.</summary>
public sealed class PortalApiException : Exception
{
    public PortalApiException(string message) : base(message) { }
}

public sealed class PortalApiClient : IDisposable
{
    private readonly HttpClient _http;

    public PortalApiClient(string baseUrl)
    {
        _http = new HttpClient
        {
            BaseAddress = new Uri(baseUrl, UriKind.Absolute),
            Timeout = TimeSpan.FromSeconds(20),
        };
        _http.DefaultRequestHeaders.UserAgent.ParseAdd($"RemoteHelpLauncher/{AppInfo.Version}");
    }

    public async Task<VerifyResult> VerifyCodeAsync(string code, CancellationToken ct)
    {
        var payload = new
        {
            code,
            pc_name = Environment.MachineName,
            os_version = Environment.OSVersion.VersionString,
            launcher_version = AppInfo.Version,
        };

        return await PostAsync<VerifyResult>("api/launcher/verify", payload, ct);
    }

    public async Task ReportStatusAsync(long sessionId, string secret, string status, string? reason, CancellationToken ct)
    {
        var payload = new
        {
            session_id = sessionId,
            launcher_secret = secret,
            status,
            reason = reason ?? string.Empty,
        };

        await PostAsync<JsonElement>("api/launcher/status", payload, ct);
    }

    public async Task<HeartbeatResult> HeartbeatAsync(long sessionId, string secret, CancellationToken ct)
    {
        var url = $"api/launcher/heartbeat?session_id={sessionId}&launcher_secret={Uri.EscapeDataString(secret)}";

        using var res = await _http.GetAsync(url, ct);
        var body = await res.Content.ReadFromJsonAsync<ApiResponse<HeartbeatResult>>(cancellationToken: ct);

        // 인증 실패(401)도 종료 신호로 취급한다.
        if (body?.Data == null)
        {
            return new HeartbeatResult { Terminate = !res.IsSuccessStatusCode };
        }

        return body.Data;
    }

    public async Task<VersionResult?> GetVersionAsync(CancellationToken ct)
    {
        try
        {
            var body = await _http.GetFromJsonAsync<ApiResponse<VersionResult>>("api/launcher/version", ct);
            return body?.Data;
        }
        catch (Exception ex) when (ex is HttpRequestException or TaskCanceledException)
        {
            AppLogger.Warn("버전 확인 실패: " + ex.Message);
            return null;
        }
    }

    private async Task<T> PostAsync<T>(string path, object payload, CancellationToken ct)
    {
        HttpResponseMessage res;

        try
        {
            res = await _http.PostAsJsonAsync(path, payload, ct);
        }
        catch (Exception ex) when (ex is HttpRequestException or TaskCanceledException)
        {
            AppLogger.Error("API 호출 실패: " + path, ex);
            throw new PortalApiException("서버에 연결하지 못했습니다. 인터넷 연결을 확인해 주세요.");
        }

        ApiResponse<T>? body = null;

        try
        {
            body = await res.Content.ReadFromJsonAsync<ApiResponse<T>>(cancellationToken: ct);
        }
        catch (JsonException)
        {
            // 아래 공통 오류 처리로 넘어간다.
        }

        if (body == null)
        {
            throw new PortalApiException("서버 응답을 해석하지 못했습니다.");
        }

        if (!body.Result || body.Data == null)
        {
            throw new PortalApiException(string.IsNullOrEmpty(body.Message)
                ? "요청을 처리하지 못했습니다."
                : body.Message);
        }

        return body.Data;
    }

    public void Dispose() => _http.Dispose();
}
