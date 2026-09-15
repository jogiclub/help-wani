/**
 * 파일 위치: launcher/RemoteHelp.App/Services/Engine/GdiScreenCapturer.cs
 * 역할: GDI BitBlt 로 가상 데스크톱 전체를 캡처한다 (기본 구현, 모든 윈도우 버전에서 동작)
 */
using System.Runtime.InteropServices;

namespace RemoteHelp.Services.Engine;

public sealed class GdiScreenCapturer : IScreenCapturer
{
    private const int SRCCOPY = 0x00CC0020;
    private const int CAPTUREBLT = 0x40000000;   // 레이어드 창(반투명)까지 포함
    private const int BI_RGB = 0;
    private const uint DIB_RGB_COLORS = 0;

    [DllImport("user32.dll")] private static extern IntPtr GetDesktopWindow();
    [DllImport("user32.dll")] private static extern IntPtr GetDC(IntPtr hwnd);
    [DllImport("user32.dll")] private static extern int ReleaseDC(IntPtr hwnd, IntPtr hdc);
    [DllImport("user32.dll")] private static extern bool GetCursorPos(out Point point);

    [DllImport("gdi32.dll")] private static extern IntPtr CreateCompatibleDC(IntPtr hdc);
    [DllImport("gdi32.dll")] private static extern IntPtr SelectObject(IntPtr hdc, IntPtr obj);
    [DllImport("gdi32.dll")] private static extern bool DeleteObject(IntPtr obj);
    [DllImport("gdi32.dll")] private static extern bool DeleteDC(IntPtr hdc);

    [DllImport("gdi32.dll")]
    private static extern bool BitBlt(IntPtr dest, int x, int y, int w, int h,
                                      IntPtr src, int srcX, int srcY, int rop);

    [DllImport("gdi32.dll")]
    private static extern IntPtr CreateDIBSection(IntPtr hdc, ref BitmapInfo info, uint usage,
                                                  out IntPtr bits, IntPtr section, uint offset);

    [StructLayout(LayoutKind.Sequential)]
    private struct Point { public int X, Y; }

    [StructLayout(LayoutKind.Sequential)]
    private struct BitmapInfoHeader
    {
        public uint Size;
        public int Width;
        public int Height;
        public ushort Planes;
        public ushort BitCount;
        public uint Compression;
        public uint SizeImage;
        public int XPelsPerMeter;
        public int YPelsPerMeter;
        public uint ClrUsed;
        public uint ClrImportant;
    }

    [StructLayout(LayoutKind.Sequential)]
    private struct BitmapInfo
    {
        public BitmapInfoHeader Header;
        public uint Colors;
    }

    private readonly IntPtr _screenDc;
    private IntPtr _memoryDc;
    private IntPtr _bitmap;
    private IntPtr _oldBitmap;
    private IntPtr _bits;
    private byte[] _buffer = Array.Empty<byte>();
    private bool _disposed;

    public string Name => "GDI";

    public ScreenGeometry Geometry { get; private set; }

    public GdiScreenCapturer()
    {
        _screenDc = GetDC(GetDesktopWindow());

        if (_screenDc == IntPtr.Zero)
        {
            throw new InvalidOperationException("화면 DC 를 얻지 못했습니다.");
        }

        Geometry = ScreenGeometry.Current();
        CreateSurface();
    }

    public (int X, int Y)? CursorPosition
    {
        get
        {
            if (!GetCursorPos(out var p))
            {
                return null;
            }

            return (p.X - Geometry.Left, p.Y - Geometry.Top);
        }
    }

    /// <summary>DIB 섹션을 만들어 픽셀 버퍼를 직접 다룬다(GetDIBits 복사를 줄인다).</summary>
    private void CreateSurface()
    {
        ReleaseSurface();

        _memoryDc = CreateCompatibleDC(_screenDc);

        var info = new BitmapInfo
        {
            Header = new BitmapInfoHeader
            {
                Size = (uint)Marshal.SizeOf<BitmapInfoHeader>(),
                Width = Geometry.Width,
                // 음수 높이 = 위에서 아래로(top-down) 저장. 인코딩 시 뒤집을 필요가 없다.
                Height = -Geometry.Height,
                Planes = 1,
                BitCount = 32,
                Compression = BI_RGB,
            },
        };

        _bitmap = CreateDIBSection(_screenDc, ref info, DIB_RGB_COLORS, out _bits, IntPtr.Zero, 0);

        if (_bitmap == IntPtr.Zero)
        {
            throw new InvalidOperationException("캡처용 비트맵을 만들지 못했습니다.");
        }

        _oldBitmap = SelectObject(_memoryDc, _bitmap);
        _buffer = new byte[Geometry.Width * 4 * Geometry.Height];
    }

    private void ReleaseSurface()
    {
        if (_memoryDc != IntPtr.Zero)
        {
            if (_oldBitmap != IntPtr.Zero)
            {
                SelectObject(_memoryDc, _oldBitmap);
                _oldBitmap = IntPtr.Zero;
            }

            DeleteDC(_memoryDc);
            _memoryDc = IntPtr.Zero;
        }

        if (_bitmap != IntPtr.Zero)
        {
            DeleteObject(_bitmap);
            _bitmap = IntPtr.Zero;
        }

        _bits = IntPtr.Zero;
    }

    public bool TryCapture(out CapturedFrame frame)
    {
        frame = default;

        if (_disposed)
        {
            return false;
        }

        // 해상도나 모니터 구성이 바뀌면 표면을 다시 만든다.
        var current = ScreenGeometry.Current();

        if (!Geometry.SameAs(current))
        {
            AppLogger.Info($"화면 구성 변경 감지: {Geometry.Width}x{Geometry.Height} -> {current.Width}x{current.Height}");
            Geometry = current;
            CreateSurface();
        }

        if (!BitBlt(_memoryDc, 0, 0, Geometry.Width, Geometry.Height,
                    _screenDc, Geometry.Left, Geometry.Top, SRCCOPY | CAPTUREBLT))
        {
            return false;
        }

        Marshal.Copy(_bits, _buffer, 0, _buffer.Length);

        frame = new CapturedFrame(_buffer, Geometry.Width, Geometry.Height, Geometry.Width * 4);
        return true;
    }

    public void Dispose()
    {
        if (_disposed)
        {
            return;
        }

        _disposed = true;
        ReleaseSurface();

        if (_screenDc != IntPtr.Zero)
        {
            ReleaseDC(GetDesktopWindow(), _screenDc);
        }
    }
}
