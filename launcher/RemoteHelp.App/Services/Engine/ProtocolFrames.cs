/**
 * 파일 위치: launcher/RemoteHelp.App/Services/Engine/ProtocolFrames.cs
 * 역할: 원격제어 프로토콜 v1 프레임 생성과 해석 (docs/protocol.md 3장)
 *
 * 정수는 모두 빅엔디안이다.
 */
using System.Buffers.Binary;
using System.Text;

namespace RemoteHelp.Services.Engine;

public static class FrameType
{
    // 에이전트 -> 뷰어
    public const byte ScreenInfo = 0x01;
    public const byte Tile = 0x02;
    public const byte FrameEnd = 0x03;
    public const byte CursorPos = 0x04;
    public const byte Clipboard = 0x05;
    public const byte Pong = 0x06;
    public const byte DisplayChanged = 0x07;

    // 뷰어 -> 에이전트
    public const byte MouseMove = 0x10;
    public const byte MouseButton = 0x11;
    public const byte MouseWheel = 0x12;
    public const byte Key = 0x13;
    public const byte ClipboardSet = 0x14;
    public const byte CtrlAltDel = 0x15;
    public const byte SetQuality = 0x16;
    public const byte RequestFullFrame = 0x17;
    public const byte Ping = 0x18;
}

public static class ProtocolWriter
{
    public static byte[] ScreenInfo(ScreenGeometry geometry)
    {
        var buffer = new byte[6 + (geometry.Monitors.Count * 8)];
        buffer[0] = FrameType.ScreenInfo;

        BinaryPrimitives.WriteUInt16BigEndian(buffer.AsSpan(1), (ushort)geometry.Width);
        BinaryPrimitives.WriteUInt16BigEndian(buffer.AsSpan(3), (ushort)geometry.Height);
        buffer[5] = (byte)geometry.Monitors.Count;

        var offset = 6;

        foreach (var monitor in geometry.Monitors)
        {
            BinaryPrimitives.WriteInt16BigEndian(buffer.AsSpan(offset), monitor.X);
            BinaryPrimitives.WriteInt16BigEndian(buffer.AsSpan(offset + 2), monitor.Y);
            BinaryPrimitives.WriteUInt16BigEndian(buffer.AsSpan(offset + 4), monitor.Width);
            BinaryPrimitives.WriteUInt16BigEndian(buffer.AsSpan(offset + 6), monitor.Height);
            offset += 8;
        }

        return buffer;
    }

    public static byte[] Tile(uint seq, EncodedTile tile)
    {
        var buffer = new byte[18 + tile.Data.Length];
        buffer[0] = FrameType.Tile;

        BinaryPrimitives.WriteUInt32BigEndian(buffer.AsSpan(1), seq);
        BinaryPrimitives.WriteUInt16BigEndian(buffer.AsSpan(5), (ushort)tile.X);
        BinaryPrimitives.WriteUInt16BigEndian(buffer.AsSpan(7), (ushort)tile.Y);
        BinaryPrimitives.WriteUInt16BigEndian(buffer.AsSpan(9), (ushort)tile.Width);
        BinaryPrimitives.WriteUInt16BigEndian(buffer.AsSpan(11), (ushort)tile.Height);
        buffer[13] = 1;   // 1 = JPEG
        BinaryPrimitives.WriteUInt32BigEndian(buffer.AsSpan(14), (uint)tile.Data.Length);

        tile.Data.CopyTo(buffer, 18);
        return buffer;
    }

    public static byte[] FrameEnd(uint seq, int tileCount, int elapsedMs)
    {
        var buffer = new byte[9];
        buffer[0] = FrameType.FrameEnd;

        BinaryPrimitives.WriteUInt32BigEndian(buffer.AsSpan(1), seq);
        BinaryPrimitives.WriteUInt16BigEndian(buffer.AsSpan(5), (ushort)Math.Min(tileCount, ushort.MaxValue));
        BinaryPrimitives.WriteUInt16BigEndian(buffer.AsSpan(7), (ushort)Math.Min(elapsedMs, ushort.MaxValue));

        return buffer;
    }

    public static byte[] CursorPos(int x, int y)
    {
        var buffer = new byte[5];
        buffer[0] = FrameType.CursorPos;

        BinaryPrimitives.WriteUInt16BigEndian(buffer.AsSpan(1), (ushort)Math.Clamp(x, 0, ushort.MaxValue));
        BinaryPrimitives.WriteUInt16BigEndian(buffer.AsSpan(3), (ushort)Math.Clamp(y, 0, ushort.MaxValue));

        return buffer;
    }

    public static byte[] Clipboard(string text)
    {
        var bytes = Encoding.UTF8.GetBytes(text);
        var buffer = new byte[5 + bytes.Length];

        buffer[0] = FrameType.Clipboard;
        BinaryPrimitives.WriteUInt32BigEndian(buffer.AsSpan(1), (uint)bytes.Length);
        bytes.CopyTo(buffer, 5);

        return buffer;
    }

    public static byte[] Pong(uint echo)
    {
        var buffer = new byte[5];
        buffer[0] = FrameType.Pong;
        BinaryPrimitives.WriteUInt32BigEndian(buffer.AsSpan(1), echo);
        return buffer;
    }

    public static byte[] DisplayChanged() => new[] { FrameType.DisplayChanged };
}

/// <summary>뷰어가 보낸 프레임</summary>
public readonly record struct ViewerCommand(
    byte Type,
    int X,
    int Y,
    int Button,
    bool Down,
    short WheelDelta,
    ushort VirtualKey,
    bool Extended,
    string? Text,
    int JpegQuality,
    int MaxFps,
    int ScalePercent,
    uint Echo);

public static class ProtocolReader
{
    /// <summary>
    /// 뷰어가 보낸 바이너리 프레임을 해석한다. 형식이 맞지 않으면 null.
    /// </summary>
    public static ViewerCommand? Parse(ReadOnlySpan<byte> data)
    {
        if (data.Length < 1)
        {
            return null;
        }

        var type = data[0];

        try
        {
            switch (type)
            {
                case FrameType.MouseMove when data.Length >= 5:
                    return new ViewerCommand(type,
                        BinaryPrimitives.ReadUInt16BigEndian(data[1..]),
                        BinaryPrimitives.ReadUInt16BigEndian(data[3..]),
                        0, false, 0, 0, false, null, 0, 0, 0, 0);

                case FrameType.MouseButton when data.Length >= 7:
                    return new ViewerCommand(type,
                        BinaryPrimitives.ReadUInt16BigEndian(data[1..]),
                        BinaryPrimitives.ReadUInt16BigEndian(data[3..]),
                        data[5], data[6] != 0, 0, 0, false, null, 0, 0, 0, 0);

                case FrameType.MouseWheel when data.Length >= 7:
                    return new ViewerCommand(type,
                        BinaryPrimitives.ReadUInt16BigEndian(data[1..]),
                        BinaryPrimitives.ReadUInt16BigEndian(data[3..]),
                        0, false,
                        BinaryPrimitives.ReadInt16BigEndian(data[5..]),
                        0, false, null, 0, 0, 0, 0);

                case FrameType.Key when data.Length >= 5:
                    return new ViewerCommand(type, 0, 0, 0,
                        data[1] != 0, 0,
                        BinaryPrimitives.ReadUInt16BigEndian(data[2..]),
                        data[4] != 0, null, 0, 0, 0, 0);

                case FrameType.ClipboardSet when data.Length >= 5:
                {
                    var length = (int)BinaryPrimitives.ReadUInt32BigEndian(data[1..]);

                    if (length < 0 || 5 + length > data.Length)
                    {
                        return null;
                    }

                    var text = Encoding.UTF8.GetString(data.Slice(5, length));
                    return new ViewerCommand(type, 0, 0, 0, false, 0, 0, false, text, 0, 0, 0, 0);
                }

                case FrameType.CtrlAltDel:
                case FrameType.RequestFullFrame:
                    return new ViewerCommand(type, 0, 0, 0, false, 0, 0, false, null, 0, 0, 0, 0);

                case FrameType.SetQuality when data.Length >= 4:
                    return new ViewerCommand(type, 0, 0, 0, false, 0, 0, false, null,
                        data[1], data[2], data[3], 0);

                case FrameType.Ping when data.Length >= 5:
                    return new ViewerCommand(type, 0, 0, 0, false, 0, 0, false, null, 0, 0, 0,
                        BinaryPrimitives.ReadUInt32BigEndian(data[1..]));

                default:
                    return null;
            }
        }
        catch (ArgumentOutOfRangeException)
        {
            return null;
        }
    }
}
