/**
 * 파일 위치: launcher/RemoteHelp.App/Views/TrayIconHost.cs
 * 역할: 작업 표시줄 알림 영역 아이콘과 종료 메뉴 제공
 */
using System.Drawing;
using System.Windows.Forms;

namespace RemoteHelp.Views;

public sealed class TrayIconHost : IDisposable
{
    private readonly NotifyIcon _icon;

    public TrayIconHost(Action onEndRequested)
    {
        var menu = new ContextMenuStrip();
        menu.Items.Add("원격지원 종료", null, (_, _) => onEndRequested());

        _icon = new NotifyIcon
        {
            Icon = LoadIcon(),
            Text = "RemoteHelp 원격지원",
            Visible = true,
            ContextMenuStrip = menu,
        };
    }

    private static Icon LoadIcon()
    {
        var path = Path.Combine(AppContext.BaseDirectory, "Assets", "app.ico");
        return File.Exists(path) ? new Icon(path) : SystemIcons.Application;
    }

    public void Dispose()
    {
        _icon.Visible = false;
        _icon.Dispose();
    }
}
