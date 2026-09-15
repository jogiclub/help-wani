/**
 * 파일 위치: launcher/RemoteHelp.App/Services/VncRunner.cs
 * 역할: 세션용 ultravnc.ini 생성과 winvnc 역방향 접속 실행/종료
 *
 * 명령행 근거는 docs/spike-report.md S-1, 설정 경로 근거는 S-2 참조.
 */
using System.Diagnostics;
using System.IO;
using System.Text;

namespace RemoteHelp.Services;

public sealed class VncRunner : IDisposable
{
    private readonly SessionWorkspace _workspace;
    private readonly JobObject _job = new();

    private Process? _process;

    public VncRunner(SessionWorkspace workspace)
    {
        _workspace = workspace;
    }

    public bool IsRunning => _process is { HasExited: false };

    /// <summary>패키지에 동봉된 winvnc.exe 경로</summary>
    public static string WinVncPath => Path.Combine(
        AppContext.BaseDirectory, "ultravnc", "winvnc.exe");

    /// <summary>
    /// 세션 설정 파일을 만든다.
    /// 상담원이 화면을 보고 제어하는 데 필요한 최소 설정만 켠다.
    /// </summary>
    public void WriteIni(string password)
    {
        var ini = new StringBuilder();
        ini.AppendLine("[ultravnc]");
        ini.AppendLine("passwd=");        // 아래에서 Win32 API 로 덮어쓴다.
        ini.AppendLine();
        ini.AppendLine("[admin]");
        ini.AppendLine("UseRegistry=0");
        ini.AppendLine("MSLogonRequired=0");
        ini.AppendLine("NewMSLogon=0");
        ini.AppendLine("service_commandline=");
        ini.AppendLine("AllowLoopback=1");     // 터널이 127.0.0.1 로 붙으므로 필요
        ini.AppendLine("LoopbackOnly=0");
        ini.AppendLine("AuthRequired=1");
        ini.AppendLine("ConnectPriority=0");
        ini.AppendLine("FileTransferEnabled=0");  // 1차 범위에서 파일 전송은 제공하지 않는다
        ini.AppendLine("DisableTrayIcon=0");
        ini.AppendLine("AllowShutdown=0");
        ini.AppendLine("AllowEditClients=0");
        ini.AppendLine("AllowProperties=0");
        ini.AppendLine("DisableSystray=0");
        ini.AppendLine();
        ini.AppendLine("[poll]");
        ini.AppendLine("TurboMode=1");
        ini.AppendLine("PollFullScreen=1");
        ini.AppendLine("PollForeground=0");

        File.WriteAllText(_workspace.IniPath, ini.ToString(), Encoding.ASCII);

        // passwd 는 8바이트 이진값이므로 반드시 Win32 API 로 기록한다.
        VncPassword.WriteToIni(_workspace.IniPath, password);

        AppLogger.Info("세션 설정 파일 생성 완료");
    }

    /// <summary>winvnc 를 역방향 접속 모드로 실행한다.</summary>
    public void Start(string repeaterId, int localPort)
    {
        if (!File.Exists(WinVncPath))
        {
            throw new FileNotFoundException("원격지원 엔진을 찾을 수 없습니다.", WinVncPath);
        }

        // -connect 의 "호스트::포트" 는 포트 번호를 뜻한다(콜론 한 개는 디스플레이 번호).
        var args = $"-config \"{_workspace.IniPath}\" -autoreconnect -id:{repeaterId} -connect 127.0.0.1::{localPort}";

        var psi = new ProcessStartInfo
        {
            FileName = WinVncPath,
            Arguments = args,
            UseShellExecute = false,
            CreateNoWindow = true,
            WorkingDirectory = Path.GetDirectoryName(WinVncPath)!,
        };

        AppLogger.Info($"winvnc 실행: -id:{repeaterId} -connect 127.0.0.1::{localPort}");

        _process = Process.Start(psi)
            ?? throw new InvalidOperationException("원격지원 엔진을 실행하지 못했습니다.");

        _job.Assign(_process);
    }

    /// <summary>winvnc 를 종료한다. 자식 프로세스까지 함께 정리한다.</summary>
    public void Stop()
    {
        try
        {
            if (_process is { HasExited: false })
            {
                _process.Kill(entireProcessTree: true);
                _process.WaitForExit(5000);
                AppLogger.Info("winvnc 종료 완료");
            }
        }
        catch (Exception ex)
        {
            AppLogger.Warn("winvnc 종료 중 오류: " + ex.Message);
        }
        finally
        {
            _process?.Dispose();
            _process = null;
        }
    }

    public void Dispose()
    {
        Stop();
        _job.Dispose();   // 잡 핸들이 닫히면 남은 자식 프로세스도 함께 종료된다.
    }
}
