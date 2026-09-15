/**
 * 파일 위치: launcher/RemoteHelp.App/Services/Engine/ScreenGeometry.cs
 * 역할: 가상 데스크톱 전체 크기와 모니터 배치 정보
 */
using System.Runtime.InteropServices;

namespace RemoteHelp.Services.Engine;

public readonly record struct MonitorArea(short X, short Y, ushort Width, ushort Height);

public sealed class ScreenGeometry
{
    private const int SM_XVIRTUALSCREEN = 76;
    private const int SM_YVIRTUALSCREEN = 77;
    private const int SM_CXVIRTUALSCREEN = 78;
    private const int SM_CYVIRTUALSCREEN = 79;

    [DllImport("user32.dll")]
    private static extern int GetSystemMetrics(int index);

    [DllImport("user32.dll")]
    private static extern bool EnumDisplayMonitors(IntPtr hdc, IntPtr rect, MonitorEnumProc callback, IntPtr data);

    private delegate bool MonitorEnumProc(IntPtr monitor, IntPtr hdc, ref Rect rect, IntPtr data);

    [StructLayout(LayoutKind.Sequential)]
    private struct Rect
    {
        public int Left, Top, Right, Bottom;
    }

    public int Left { get; private init; }
    public int Top { get; private init; }
    public int Width { get; private init; }
    public int Height { get; private init; }
    public IReadOnlyList<MonitorArea> Monitors { get; private init; } = Array.Empty<MonitorArea>();

    /// <summary>현재 가상 데스크톱 구성을 읽는다.</summary>
    public static ScreenGeometry Current()
    {
        var left = GetSystemMetrics(SM_XVIRTUALSCREEN);
        var top = GetSystemMetrics(SM_YVIRTUALSCREEN);
        var width = GetSystemMetrics(SM_CXVIRTUALSCREEN);
        var height = GetSystemMetrics(SM_CYVIRTUALSCREEN);

        var monitors = new List<MonitorArea>();

        EnumDisplayMonitors(IntPtr.Zero, IntPtr.Zero, (IntPtr _, IntPtr _, ref Rect r, IntPtr _) =>
        {
            // 좌표는 가상 데스크톱 원점 기준으로 바꿔 보낸다.
            monitors.Add(new MonitorArea(
                (short)(r.Left - left),
                (short)(r.Top - top),
                (ushort)(r.Right - r.Left),
                (ushort)(r.Bottom - r.Top)));
            return true;
        }, IntPtr.Zero);

        return new ScreenGeometry
        {
            Left = left,
            Top = top,
            Width = Math.Max(1, width),
            Height = Math.Max(1, height),
            Monitors = monitors,
        };
    }

    public bool SameAs(ScreenGeometry other)
        => other != null
           && Left == other.Left && Top == other.Top
           && Width == other.Width && Height == other.Height
           && Monitors.Count == other.Monitors.Count;
}
