# Capture EuroScope window to a specified PNG file.
# Usage: .\capture-window.ps1 -Output "path\to\file.png"

param(
    [Parameter(Mandatory=$true)]
    [string]$Output,
    [string]$ProcessName = "EuroScope"
)

Add-Type -AssemblyName System.Windows.Forms, System.Drawing

Add-Type @"
using System;
using System.Runtime.InteropServices;
public class Win32 {
    [DllImport("user32.dll")]
    public static extern bool GetWindowRect(IntPtr hwnd, out RECT rect);
    [DllImport("user32.dll")]
    public static extern bool GetClientRect(IntPtr hwnd, out RECT rect);
    [DllImport("user32.dll")]
    public static extern bool ClientToScreen(IntPtr hwnd, ref POINT pt);
    [DllImport("user32.dll")]
    public static extern bool SetForegroundWindow(IntPtr hwnd);
    [DllImport("user32.dll")]
    public static extern bool ShowWindow(IntPtr hwnd, int cmdShow);
    [DllImport("user32.dll")]
    public static extern bool IsIconic(IntPtr hwnd);
    [StructLayout(LayoutKind.Sequential)]
    public struct RECT { public int Left, Top, Right, Bottom; }
    [StructLayout(LayoutKind.Sequential)]
    public struct POINT { public int X, Y; }
}
"@

$proc = Get-Process -Name $ProcessName -ErrorAction SilentlyContinue | Where-Object { $_.MainWindowTitle -ne '' } | Select-Object -First 1

if (-not $proc) {
    Write-Error "Process '$ProcessName' not found or has no main window."
    exit 1
}

$hwnd = $proc.MainWindowHandle

# Restore if minimized
if ([Win32]::IsIconic($hwnd)) {
    [Win32]::ShowWindow($hwnd, 9) | Out-Null   # SW_RESTORE
    Start-Sleep -Milliseconds 200
}

# Capture only the client area — excludes title bar, window borders, and any
# Windows taskbar overlap at the bottom. GetClientRect returns dimensions
# relative to the client; ClientToScreen converts (0,0) to screen coords.
$clientRect = New-Object Win32+RECT
[Win32]::GetClientRect($hwnd, [ref]$clientRect) | Out-Null

$origin = New-Object Win32+POINT
$origin.X = 0
$origin.Y = 0
[Win32]::ClientToScreen($hwnd, [ref]$origin) | Out-Null

$rect = New-Object Win32+RECT
$rect.Left = $origin.X
$rect.Top = $origin.Y
$rect.Right = $origin.X + $clientRect.Right
$rect.Bottom = $origin.Y + $clientRect.Bottom

$w = $rect.Right - $rect.Left
$h = $rect.Bottom - $rect.Top

if ($w -le 0 -or $h -le 0) {
    Write-Error "Invalid window size: ${w}x${h}"
    exit 1
}

# Capture
$bmp = New-Object System.Drawing.Bitmap $w, $h
$g = [System.Drawing.Graphics]::FromImage($bmp)
$g.CopyFromScreen($rect.Left, $rect.Top, 0, 0, $bmp.Size)

# Auto-detect Windows taskbar at bottom (sequence of rows where centre pixel
# is bright grey/white ~240,240,240). Trim those rows.
$cropBottom = 0
$centreX = [int]($w / 2)
for ($y = $h - 1; $y -ge $h - 100; $y--) {
    $px = $bmp.GetPixel($centreX, $y)
    if ($px.R -gt 200 -and $px.G -gt 200 -and $px.B -gt 200) {
        $cropBottom = $h - $y
    } else {
        break
    }
}
# Trim 1px off the top to remove the title-bar artifact
$cropTop = 1

if ($cropBottom -gt 0 -or $cropTop -gt 0) {
    $newH = $h - $cropBottom - $cropTop
    $cropped = New-Object System.Drawing.Bitmap $w, $newH
    $cg = [System.Drawing.Graphics]::FromImage($cropped)
    $srcRect = New-Object System.Drawing.Rectangle 0, $cropTop, $w, $newH
    $cg.DrawImage($bmp, 0, 0, $srcRect, [System.Drawing.GraphicsUnit]::Pixel)
    $cropped.Save($Output, [System.Drawing.Imaging.ImageFormat]::Png)
    $cg.Dispose()
    $cropped.Dispose()
    $bmp.Dispose()
    $g.Dispose()
    Write-Output "Saved ${w}x${newH} -> $Output (trimmed ${cropTop}px top, ${cropBottom}px bottom)"
} else {
    $bmp.Save($Output, [System.Drawing.Imaging.ImageFormat]::Png)
    $bmp.Dispose()
    $g.Dispose()
    Write-Output "Saved ${w}x${h} -> $Output"
}
