/**
 * 파일 위치: launcher/RemoteHelp.App/Services/Engine/TileEncoder.cs
 * 역할: 화면을 타일로 나누고 바뀐 타일만 JPEG 으로 인코딩한다 (docs/protocol.md 4장)
 */
using System.IO;
using System.Windows.Media;
using System.Windows.Media.Imaging;

namespace RemoteHelp.Services.Engine;

public readonly record struct EncodedTile(int X, int Y, int Width, int Height, byte[] Data);

public sealed class TileEncoder
{
    public const int TileSize = 128;

    private readonly int _width;
    private readonly int _height;
    private readonly int _cols;
    private readonly int _rows;
    private readonly ulong[] _hashes;
    private readonly byte[] _tileBuffer;

    private bool _hasPrevious;

    public TileEncoder(int width, int height)
    {
        _width = width;
        _height = height;
        _cols = (width + TileSize - 1) / TileSize;
        _rows = (height + TileSize - 1) / TileSize;
        _hashes = new ulong[_cols * _rows];
        _tileBuffer = new byte[TileSize * TileSize * 4];
    }

    public int TileCount => _cols * _rows;

    /// <summary>다음 프레임에서 전체 타일을 다시 보내게 한다.</summary>
    public void Invalidate() => _hasPrevious = false;

    /// <summary>
    /// 바뀐 타일을 찾아 JPEG 으로 인코딩해 돌려준다.
    /// </summary>
    public List<EncodedTile> EncodeChangedTiles(CapturedFrame frame, int quality)
    {
        var result = new List<EncodedTile>();
        var full = !_hasPrevious;

        for (var row = 0; row < _rows; row++)
        {
            for (var col = 0; col < _cols; col++)
            {
                var x = col * TileSize;
                var y = row * TileSize;
                var w = Math.Min(TileSize, _width - x);
                var h = Math.Min(TileSize, _height - y);

                if (w <= 0 || h <= 0)
                {
                    continue;
                }

                var hash = HashTile(frame, x, y, w, h);
                var index = (row * _cols) + col;

                if (!full && _hashes[index] == hash)
                {
                    continue;
                }

                _hashes[index] = hash;
                result.Add(new EncodedTile(x, y, w, h, EncodeTile(frame, x, y, w, h, quality)));
            }
        }

        _hasPrevious = true;
        return result;
    }

    /// <summary>
    /// FNV-1a 변형. 픽셀을 4바이트 단위로 읽어 타일 하나의 지문을 만든다.
    /// 암호학적 용도가 아니므로 충돌 가능성은 무시할 수준이다.
    /// </summary>
    private static ulong HashTile(CapturedFrame frame, int x, int y, int w, int h)
    {
        const ulong offsetBasis = 14695981039346656037UL;
        const ulong prime = 1099511628211UL;

        var hash = offsetBasis;
        var pixels = frame.Pixels;

        for (var row = 0; row < h; row++)
        {
            var offset = ((y + row) * frame.Stride) + (x * 4);
            var end = offset + (w * 4);

            for (var i = offset; i < end; i += 4)
            {
                var value = (uint)(pixels[i] | (pixels[i + 1] << 8) | (pixels[i + 2] << 16));
                hash = (hash ^ value) * prime;
            }
        }

        return hash;
    }

    /// <summary>타일 영역을 잘라 JPEG 으로 인코딩한다.</summary>
    private byte[] EncodeTile(CapturedFrame frame, int x, int y, int w, int h, int quality)
    {
        var stride = w * 4;

        for (var row = 0; row < h; row++)
        {
            Array.Copy(frame.Pixels,
                ((y + row) * frame.Stride) + (x * 4),
                _tileBuffer,
                row * stride,
                stride);
        }

        var source = BitmapSource.Create(w, h, 96, 96, PixelFormats.Bgr32, null, _tileBuffer, h * stride, stride);
        source.Freeze();

        var encoder = new JpegBitmapEncoder { QualityLevel = Math.Clamp(quality, 10, 95) };
        encoder.Frames.Add(BitmapFrame.Create(source));

        using var stream = new MemoryStream(8 * 1024);
        encoder.Save(stream);
        return stream.ToArray();
    }
}
