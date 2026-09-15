/**
 * 파일 위치: launcher/RemoteHelp.App/Services/Engine/DxgiScreenCapturer.cs
 * 역할: DXGI Desktop Duplication 으로 화면을 캡처한다 (가능한 환경에서만 사용, 실패 시 GDI 로 폴백)
 *
 * 제약
 *  - 단일 출력(주 모니터)만 복제한다. 다중 모니터는 GDI 로 폴백한다.
 *  - 원격 데스크톱 세션, 일부 가상 머신, 드라이버 문제 환경에서는 생성 자체가 실패한다.
 */
using SharpGen.Runtime;
using Vortice.Direct3D;
using Vortice.Direct3D11;
using Vortice.DXGI;

namespace RemoteHelp.Services.Engine;

public sealed class DxgiScreenCapturer : IScreenCapturer
{
    private const int AcquireTimeoutMs = 200;

    private readonly ID3D11Device _device;
    private readonly ID3D11DeviceContext _context;
    private readonly IDXGIOutputDuplication _duplication;
    private readonly ID3D11Texture2D _staging;
    private readonly byte[] _buffer;

    private (int X, int Y)? _cursor;
    private bool _hasFrame;
    private bool _disposed;

    public string Name => "DXGI";

    public ScreenGeometry Geometry { get; }

    public (int X, int Y)? CursorPosition => _cursor;

    private DxgiScreenCapturer(ID3D11Device device, ID3D11DeviceContext context,
                               IDXGIOutputDuplication duplication, ID3D11Texture2D staging,
                               ScreenGeometry geometry)
    {
        _device = device;
        _context = context;
        _duplication = duplication;
        _staging = staging;
        Geometry = geometry;
        _buffer = new byte[geometry.Width * 4 * geometry.Height];
    }

    /// <summary>
    /// 사용할 수 있으면 인스턴스를, 아니면 null 을 돌려준다. 예외를 밖으로 던지지 않는다.
    /// </summary>
    public static DxgiScreenCapturer? TryCreate()
    {
        ID3D11Device? device = null;
        ID3D11DeviceContext? context = null;

        try
        {
            var geometry = ScreenGeometry.Current();

            // 다중 모니터는 출력별 복제를 합쳐야 해서 1차 범위에서는 GDI 를 쓴다.
            if (geometry.Monitors.Count != 1)
            {
                AppLogger.Info($"모니터가 {geometry.Monitors.Count}개여서 DXGI 대신 GDI 를 사용합니다.");
                return null;
            }

            var result = D3D11.D3D11CreateDevice(
                null,
                DriverType.Hardware,
                DeviceCreationFlags.BgraSupport,
                new[] { FeatureLevel.Level_11_0, FeatureLevel.Level_10_0 },
                out device,
                out context);

            if (result.Failure || device == null || context == null)
            {
                AppLogger.Info("D3D11 장치를 만들지 못해 GDI 를 사용합니다.");
                return null;
            }

            using var dxgiDevice = device.QueryInterface<IDXGIDevice>();
            using var adapter = dxgiDevice.GetAdapter();

            if (adapter.EnumOutputs(0, out var output).Failure || output == null)
            {
                AppLogger.Info("DXGI 출력이 없어 GDI 를 사용합니다.");
                device.Dispose();
                context.Dispose();
                return null;
            }

            using (output)
            {
                using var output1 = output.QueryInterface<IDXGIOutput1>();
                var duplication = output1.DuplicateOutput(device);

                var description = new Texture2DDescription
                {
                    Width = (uint)geometry.Width,
                    Height = (uint)geometry.Height,
                    MipLevels = 1,
                    ArraySize = 1,
                    Format = Format.B8G8R8A8_UNorm,
                    SampleDescription = new SampleDescription(1, 0),
                    Usage = ResourceUsage.Staging,
                    BindFlags = BindFlags.None,
                    CPUAccessFlags = CpuAccessFlags.Read,
                    MiscFlags = ResourceOptionFlags.None,
                };

                var staging = device.CreateTexture2D(description);

                AppLogger.Info($"DXGI Desktop Duplication 사용 ({geometry.Width}x{geometry.Height})");
                return new DxgiScreenCapturer(device, context, duplication, staging, geometry);
            }
        }
        catch (Exception ex)
        {
            AppLogger.Info("DXGI 초기화 실패로 GDI 를 사용합니다: " + ex.Message);
            device?.Dispose();
            context?.Dispose();
            return null;
        }
    }

    public bool TryCapture(out CapturedFrame frame)
    {
        frame = default;

        if (_disposed)
        {
            return false;
        }

        IDXGIResource? resource = null;

        try
        {
            var result = _duplication.AcquireNextFrame(AcquireTimeoutMs, out var info, out resource);

            if (result == Vortice.DXGI.ResultCode.WaitTimeout)
            {
                // 화면 변화가 없다. 직전 프레임을 그대로 쓸 수 있으면 넘겨준다.
                return ReturnLastFrame(ref frame);
            }

            if (result.Failure || resource == null)
            {
                return false;
            }

            if (info.PointerPosition.Visible)
            {
                _cursor = (info.PointerPosition.Position.X, info.PointerPosition.Position.Y);
            }

            using (var texture = resource.QueryInterface<ID3D11Texture2D>())
            {
                _context.CopyResource(_staging, texture);
            }

            var map = _context.Map(_staging, 0, Vortice.Direct3D11.MapMode.Read, Vortice.Direct3D11.MapFlags.None);

            try
            {
                var rowBytes = Geometry.Width * 4;

                for (var y = 0; y < Geometry.Height; y++)
                {
                    var source = map.DataPointer + (int)(y * map.RowPitch);
                    System.Runtime.InteropServices.Marshal.Copy(source, _buffer, y * rowBytes, rowBytes);
                }
            }
            finally
            {
                _context.Unmap(_staging, 0);
            }

            _hasFrame = true;
            frame = new CapturedFrame(_buffer, Geometry.Width, Geometry.Height, Geometry.Width * 4);
            return true;
        }
        catch (SharpGenException ex)
        {
            AppLogger.Warn("DXGI 캡처 오류: " + ex.Message);
            return false;
        }
        finally
        {
            resource?.Dispose();

            try
            {
                _duplication.ReleaseFrame();
            }
            catch (SharpGenException)
            {
                // 이미 해제된 경우 무시한다.
            }
        }
    }

    private bool ReturnLastFrame(ref CapturedFrame frame)
    {
        if (!_hasFrame)
        {
            return false;
        }

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

        _staging.Dispose();
        _duplication.Dispose();
        _context.Dispose();
        _device.Dispose();
    }
}
