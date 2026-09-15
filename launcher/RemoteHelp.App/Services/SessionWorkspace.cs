/**
 * 파일 위치: launcher/RemoteHelp.App/Services/SessionWorkspace.cs
 * 역할: 세션별 작업 폴더(설정 파일 위치) 생성과 삭제, 이전 잔여 폴더 정리
 */
using System.IO;

namespace RemoteHelp.Services;

public sealed class SessionWorkspace : IDisposable
{
    private SessionWorkspace(string path)
    {
        Path = path;
    }

    public string Path { get; }

    public string IniPath => System.IO.Path.Combine(Path, "ultravnc.ini");

    public static string RootDirectory => System.IO.Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
        "RemoteHelp", "sessions");

    public static SessionWorkspace Create()
    {
        var path = System.IO.Path.Combine(RootDirectory, Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(path);
        AppLogger.Info("세션 작업 폴더 생성: " + path);
        return new SessionWorkspace(path);
    }

    /// <summary>이전 실행에서 남은 세션 폴더를 지운다.</summary>
    public static void CleanupOrphans()
    {
        try
        {
            if (!Directory.Exists(RootDirectory))
            {
                return;
            }

            foreach (var dir in Directory.GetDirectories(RootDirectory))
            {
                try
                {
                    Directory.Delete(dir, true);
                    AppLogger.Info("이전 세션 폴더 정리: " + dir);
                }
                catch (IOException ex)
                {
                    AppLogger.Warn("이전 세션 폴더 정리 실패: " + ex.Message);
                }
            }
        }
        catch (Exception ex)
        {
            AppLogger.Warn("세션 폴더 정리 중 오류: " + ex.Message);
        }
    }

    public void Dispose()
    {
        try
        {
            if (Directory.Exists(Path))
            {
                Directory.Delete(Path, true);
                AppLogger.Info("세션 작업 폴더 삭제: " + Path);
            }
        }
        catch (IOException ex)
        {
            AppLogger.Warn("세션 작업 폴더 삭제 실패: " + ex.Message);
        }
    }
}
