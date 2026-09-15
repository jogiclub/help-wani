/**
 * 파일 위치: launcher/RemoteHelp.App/Services/VncPassword.cs
 * 역할: UltraVNC ultravnc.ini 에 세션 비밀번호를 기록
 *
 * 배경(docs/spike-report.md S-3)
 *  - UltraVNC 는 ini 의 passwd 값을 WritePrivateProfileStruct 로 기록한 8바이트 이진값으로 읽는다.
 *  - 그 8바이트는 고정키 {23,82,107,6,35,78,88,7} 로 DES-ECB 암호화한 비밀번호다.
 *  - VNC 인증(DES)은 8바이트 고정이므로 비밀번호는 정확히 8자여야 한다.
 *  - VNC 계열 DES 는 키 바이트의 비트 순서를 뒤집어 사용한다.
 */
using System.Runtime.InteropServices;
using System.Security.Cryptography;
using System.Text;

namespace RemoteHelp.Services;

public static class VncPassword
{
    public const int Length = 8;

    private static readonly byte[] FixedKey = { 23, 82, 107, 6, 35, 78, 88, 7 };

    [DllImport("kernel32.dll", CharSet = CharSet.Ansi, SetLastError = true)]
    private static extern bool WritePrivateProfileStructA(
        string lpszSection, string lpszKey, byte[] lpStruct, uint uSizeStruct, string szFile);

    /// <summary>비밀번호를 UltraVNC 형식으로 암호화한다.</summary>
    public static byte[] Encrypt(string password)
    {
        var plain = new byte[Length];
        var raw = Encoding.ASCII.GetBytes(password);
        Array.Copy(raw, plain, Math.Min(raw.Length, Length));

        using var des = DES.Create();
        des.Mode = CipherMode.ECB;
        des.Padding = PaddingMode.None;
        des.Key = MirrorBits(FixedKey);

        using var encryptor = des.CreateEncryptor();
        return encryptor.TransformFinalBlock(plain, 0, Length);
    }

    /// <summary>ultravnc.ini 의 [UltraVNC] passwd 항목에 기록한다.</summary>
    public static void WriteToIni(string iniPath, string password)
    {
        if (password.Length != Length)
        {
            throw new ArgumentException($"VNC 비밀번호는 정확히 {Length}자여야 합니다.", nameof(password));
        }

        var encrypted = Encrypt(password);

        if (!WritePrivateProfileStructA("UltraVNC", "passwd", encrypted, (uint)encrypted.Length, iniPath))
        {
            throw new IOException("설정 파일에 비밀번호를 기록하지 못했습니다. (오류 "
                + Marshal.GetLastWin32Error() + ")");
        }
    }

    /// <summary>VNC 계열 DES 는 키 각 바이트의 비트 순서를 뒤집어 쓴다.</summary>
    private static byte[] MirrorBits(byte[] key)
    {
        var mirrored = new byte[key.Length];

        for (var i = 0; i < key.Length; i++)
        {
            byte value = 0;

            for (var bit = 0; bit < 8; bit++)
            {
                if ((key[i] & (1 << bit)) != 0)
                {
                    value |= (byte)(1 << (7 - bit));
                }
            }

            mirrored[i] = value;
        }

        return mirrored;
    }
}
