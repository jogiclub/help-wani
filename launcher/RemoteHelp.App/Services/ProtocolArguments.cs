/**
 * 파일 위치: launcher/RemoteHelp.App/Services/ProtocolArguments.cs
 * 역할: remotehelp:// 프로토콜 실행 인자 해석
 */
using System.Web;

namespace RemoteHelp.Services;

public static class ProtocolArguments
{
    /// <summary>
    /// remotehelp://connect?org=demo 형태의 인자에서 조직 코드를 뽑는다.
    /// 조직 코드는 영문/숫자/하이픈/밑줄만 허용한다.
    /// </summary>
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

            var org = HttpUtility.ParseQueryString(uri.Query).Get("org");

            if (string.IsNullOrWhiteSpace(org))
            {
                continue;
            }

            org = org.Trim();

            if (org.Length is < 1 or > 32)
            {
                continue;
            }

            foreach (var c in org)
            {
                if (!char.IsLetterOrDigit(c) && c != '-' && c != '_')
                {
                    return null;
                }
            }

            return org;
        }

        return null;
    }
}
