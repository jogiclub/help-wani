/**
 * 파일 위치: launcher/RemoteHelp.App/Services/AppLogger.cs
 * 역할: 로컬 로그 기록 (7일 보관, 비밀번호와 토큰은 기록하지 않음)
 */
using System.IO;
using System.Text;

namespace RemoteHelp.Services;

public static class AppLogger
{
    private static readonly object Gate = new();
    private static string _logFile = string.Empty;

    public static string LogDirectory =>
        Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
            "RemoteHelp", "logs");

    public static void Initialize()
    {
        Directory.CreateDirectory(LogDirectory);
        _logFile = Path.Combine(LogDirectory, $"launcher-{DateTime.Now:yyyyMMdd}.log");
        PurgeOld();
    }

    public static void Info(string message) => Write("INFO", message);

    public static void Warn(string message) => Write("WARN", message);

    public static void Error(string message, Exception? ex = null)
        => Write("ERROR", ex == null ? message : $"{message}: {ex.GetType().Name} {ex.Message}");

    private static void Write(string level, string message)
    {
        if (string.IsNullOrEmpty(_logFile))
        {
            return;
        }

        var line = $"{DateTime.Now:yyyy-MM-dd HH:mm:ss} [{level}] {message}{Environment.NewLine}";

        lock (Gate)
        {
            try
            {
                File.AppendAllText(_logFile, line, Encoding.UTF8);
            }
            catch (IOException)
            {
                // 로그 기록 실패가 기능을 막아서는 안 된다.
            }
        }
    }

    /// <summary>7일이 지난 로그 파일을 삭제한다.</summary>
    private static void PurgeOld()
    {
        try
        {
            var limit = DateTime.Now.AddDays(-7);

            foreach (var file in Directory.GetFiles(LogDirectory, "launcher-*.log"))
            {
                if (File.GetLastWriteTime(file) < limit)
                {
                    File.Delete(file);
                }
            }
        }
        catch (IOException)
        {
        }
    }
}
