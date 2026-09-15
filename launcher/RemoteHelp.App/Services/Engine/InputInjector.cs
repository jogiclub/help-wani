/**
 * 파일 위치: launcher/RemoteHelp.App/Services/Engine/InputInjector.cs
 * 역할: 상담원이 보낸 마우스/키보드 입력을 고객 PC 에 주입한다 (SendInput)
 *
 * 제약: 비관리자 권한으로 실행되므로 UAC 동의 창 등 더 높은 무결성 수준의 창에는
 *       입력을 보낼 수 없다(윈도우 UIPI). docs/spike-report.md S-7 참조.
 */
using System.Runtime.InteropServices;

namespace RemoteHelp.Services.Engine;

public sealed class InputInjector
{
    private const int InputMouse = 0;
    private const int InputKeyboard = 1;

    private const uint MouseMove = 0x0001;
    private const uint MouseAbsolute = 0x8000;
    private const uint MouseVirtualDesk = 0x4000;
    private const uint MouseLeftDown = 0x0002;
    private const uint MouseLeftUp = 0x0004;
    private const uint MouseRightDown = 0x0008;
    private const uint MouseRightUp = 0x0010;
    private const uint MouseMiddleDown = 0x0020;
    private const uint MouseMiddleUp = 0x0040;
    private const uint MouseWheel = 0x0800;

    private const uint KeyUp = 0x0002;
    private const uint KeyExtended = 0x0001;

    private const int SM_XVIRTUALSCREEN = 76;
    private const int SM_YVIRTUALSCREEN = 77;
    private const int SM_CXVIRTUALSCREEN = 78;
    private const int SM_CYVIRTUALSCREEN = 79;

    [DllImport("user32.dll", SetLastError = true)]
    private static extern uint SendInput(uint count, Input[] inputs, int size);

    [DllImport("user32.dll")]
    private static extern int GetSystemMetrics(int index);

    [StructLayout(LayoutKind.Sequential)]
    private struct MouseInput
    {
        public int Dx;
        public int Dy;
        public uint MouseData;
        public uint Flags;
        public uint Time;
        public IntPtr ExtraInfo;
    }

    [StructLayout(LayoutKind.Sequential)]
    private struct KeyboardInput
    {
        public ushort VirtualKey;
        public ushort ScanCode;
        public uint Flags;
        public uint Time;
        public IntPtr ExtraInfo;
    }

    [StructLayout(LayoutKind.Explicit)]
    private struct InputUnion
    {
        [FieldOffset(0)] public MouseInput Mouse;
        [FieldOffset(0)] public KeyboardInput Keyboard;
    }

    [StructLayout(LayoutKind.Sequential)]
    private struct Input
    {
        public int Type;
        public InputUnion Union;
    }

    /// <summary>
    /// 마우스 절대 좌표는 가상 데스크톱을 0~65535 로 정규화한 값이어야 한다.
    /// </summary>
    private static (int X, int Y) Normalize(int x, int y)
    {
        var width = Math.Max(1, GetSystemMetrics(SM_CXVIRTUALSCREEN));
        var height = Math.Max(1, GetSystemMetrics(SM_CYVIRTUALSCREEN));

        // 전달받은 좌표는 가상 데스크톱 원점 기준이다.
        var nx = (int)Math.Round(x * 65535.0 / width);
        var ny = (int)Math.Round(y * 65535.0 / height);

        return (Math.Clamp(nx, 0, 65535), Math.Clamp(ny, 0, 65535));
    }

    private static void Send(params Input[] inputs)
    {
        var sent = SendInput((uint)inputs.Length, inputs, Marshal.SizeOf<Input>());

        if (sent != inputs.Length)
        {
            AppLogger.Warn("입력 주입 실패, 오류 " + Marshal.GetLastWin32Error());
        }
    }

    public void MoveMouse(int x, int y)
    {
        var (nx, ny) = Normalize(x, y);

        Send(new Input
        {
            Type = InputMouse,
            Union = new InputUnion
            {
                Mouse = new MouseInput
                {
                    Dx = nx,
                    Dy = ny,
                    Flags = MouseMove | MouseAbsolute | MouseVirtualDesk,
                },
            },
        });
    }

    public void MouseButton(int x, int y, int button, bool down)
    {
        var (nx, ny) = Normalize(x, y);

        var flag = button switch
        {
            1 => down ? MouseRightDown : MouseRightUp,
            2 => down ? MouseMiddleDown : MouseMiddleUp,
            _ => down ? MouseLeftDown : MouseLeftUp,
        };

        Send(new Input
        {
            Type = InputMouse,
            Union = new InputUnion
            {
                Mouse = new MouseInput
                {
                    Dx = nx,
                    Dy = ny,
                    Flags = MouseMove | MouseAbsolute | MouseVirtualDesk | flag,
                },
            },
        });
    }

    public void MouseWheelScroll(int x, int y, short delta)
    {
        var (nx, ny) = Normalize(x, y);

        Send(new Input
        {
            Type = InputMouse,
            Union = new InputUnion
            {
                Mouse = new MouseInput
                {
                    Dx = nx,
                    Dy = ny,
                    MouseData = unchecked((uint)delta),
                    Flags = MouseMove | MouseAbsolute | MouseVirtualDesk | MouseWheel,
                },
            },
        });
    }

    public void Key(ushort virtualKey, bool down, bool extended)
    {
        uint flags = 0;

        if (!down)
        {
            flags |= KeyUp;
        }

        if (extended)
        {
            flags |= KeyExtended;
        }

        Send(new Input
        {
            Type = InputKeyboard,
            Union = new InputUnion
            {
                Keyboard = new KeyboardInput
                {
                    VirtualKey = virtualKey,
                    Flags = flags,
                },
            },
        });
    }

    /// <summary>
    /// Ctrl+Alt+Del 은 보안 주의 시퀀스(SAS)여서 SendInput 으로는 전달되지 않는다.
    /// 대신 화면 잠금 등에서 쓰이는 조합만 흉내 내고, 실제 SAS 는 고객에게 안내한다.
    /// </summary>
    public bool SendCtrlAltDel()
    {
        AppLogger.Info("Ctrl+Alt+Del 요청 수신 (SAS 는 권한상 주입할 수 없음)");
        return false;
    }
}
