/**
 * 파일 위치: launcher/RemoteHelp.App/Services/Engine/IScreenCapturer.cs
 * 역할: 화면 캡처 구현이 공통으로 제공하는 계약
 */
namespace RemoteHelp.Services.Engine;

/// <summary>
/// 한 번 캡처한 화면. 픽셀은 32비트 BGRA(BGRX) 이며 버퍼는 다음 캡처 때 재사용된다.
/// 호출자는 반환된 배열을 보관하지 말고 즉시 사용해야 한다.
/// </summary>
public readonly record struct CapturedFrame(byte[] Pixels, int Width, int Height, int Stride);

public interface IScreenCapturer : IDisposable
{
    /// <summary>구현 이름 (로그와 진단용)</summary>
    string Name { get; }

    ScreenGeometry Geometry { get; }

    /// <summary>
    /// 화면을 캡처한다. 변경이 없어 새 프레임이 없으면 false 를 돌려준다.
    /// </summary>
    bool TryCapture(out CapturedFrame frame);

    /// <summary>현재 커서 위치(가상 데스크톱 기준). 구현이 제공하지 않으면 null.</summary>
    (int X, int Y)? CursorPosition { get; }
}
