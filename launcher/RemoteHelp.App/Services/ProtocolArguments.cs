/**
 * 파일 위치: launcher/RemoteHelp.App/Services/ProtocolArguments.cs
 * 역할: 조직 코드 판별 (remotehelp:// 프로토콜 인자, 실행 파일 이름 순으로 찾는다)
 */
using System.Text.RegularExpressions;
using System.Web;

namespace RemoteHelp.Services;

public static partial class ProtocolArguments
{
    /// <summary>조직 코드 허용 문자: 영문, 숫자, 하이픈, 밑줄 (1~32자)</summary>
    [GeneratedRegex(@"^[A-Za-z0-9_-]{1,32}$")]
    private static partial Regex OrgCodePattern();

    /// <summary>다운로드된 파일 이름 규칙: RemoteHelp_{조직코드}.exe</summary>
    [GeneratedRegex(@"^RemoteHelp[_-](?<org>[A-Za-z0-9_-]{1,32})$", RegexOptions.IgnoreCase)]
    private static partial Regex FileNamePattern();

    /// <summary>
    /// 조직 코드를 찾는다.
    ///   1) remotehelp://connect?org=... 프로토콜 인자
    ///   2) 실행 파일 이름 (포털에서 RemoteHelp_{조직코드}.exe 로 내려받은 경우)
    /// 둘 다 없으면 null.
    /// </summary>
    public static string? ResolveOrgCode(string[] args)
        => ParseOrgCode(args) ?? FromExecutableName();

    /// <summary>remotehelp://connect?org=demo 형태의 인자에서 조직 코드를 뽑는다.</summary>
    public static string? ParseOrgCode(string[] args)
    {
        foreach (var arg in args)
        {
            if (!arg.StartsWith("remotehelp://", StringComparison.OrdinalIgnoreCase))
            {
                continue;
            }

            if (!Uri.TryCreate(arg, UriKind.Absolute, out var uri))
            {
                continue;
            }

            var org = HttpUtility.ParseQueryString(uri.Query).Get("org")?.Trim();

            if (!string.IsNullOrEmpty(org) && OrgCodePattern().IsMatch(org))
            {
                return org;
            }
        }

        return null;
    }

    /// <summary>
    /// 실행 파일 이름에서 조직 코드를 읽는다.
    /// 포털이 다운로드할 때 RemoteHelp_{조직코드}.exe 로 이름을 붙여 내려준다.
    /// 고객이 파일 이름을 바꿨거나 규칙에 맞지 않으면 null 을 돌려준다.
    /// </summary>
    public static string? FromExecutableName()
    {
        try
        {
            var path = Environment.ProcessPath;

            if (string.IsNullOrEmpty(path))
            {
                return null;
            }

            var name = Path.GetFileNameWithoutExtension(path);

            // 브라우저가 중복 이름에 붙이는 " (1)" 같은 꼬리표를 떼어낸다.
            name = Regex.Replace(name, @"\s*\(\d+\)$", string.Empty).Trim();

            var match = FileNamePattern().Match(name);

            return match.Success ? match.Groups["org"].Value : null;
        }
        catch (Exception ex)
        {
            AppLogger.Warn("실행 파일 이름 해석 실패: " + ex.Message);
            return null;
        }
    }
}
