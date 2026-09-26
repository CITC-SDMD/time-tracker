<#
.SYNOPSIS
  Sends input the way a macro program or a jiggler does, to test the activity check by hand
  (docs/LIVE_TEST_ACTIVITY_CHECK.md). Nothing to install: it uses the Windows SendInput call.

.DESCRIPTION
  Modes:
    macro   moves the mouse in quick back-and-forth steps and taps the Shift key now and then. Never clicks, never
            types a letter, so it is safe to run while other windows are open.
    jiggler moves the mouse 1 pixel out and back every 30 seconds, and nothing else.

  Input sent this way has no keyboard or mouse behind it, so the desktop app counts it as software input. A real
  USB jiggler cannot be simulated here: it looks like a real mouse to Windows.

.PARAMETER Mode     macro or jiggler
.PARAMETER Minutes  How long to run (default 30). Press Ctrl+C to stop earlier.

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File scripts\live-test\simulate-input.ps1 -Mode macro -Minutes 30
#>
param(
  [ValidateSet('macro', 'jiggler')][string]$Mode = 'macro',
  [int]$Minutes = 30
)

Add-Type -TypeDefinition @"
using System;
using System.Runtime.InteropServices;

public static class FakeInput {
  [StructLayout(LayoutKind.Sequential)]
  struct MOUSEINPUT { public int dx; public int dy; public uint mouseData; public uint dwFlags; public uint time; public IntPtr dwExtraInfo; }
  [StructLayout(LayoutKind.Sequential)]
  struct KEYBDINPUT { public ushort wVk; public ushort wScan; public uint dwFlags; public uint time; public IntPtr dwExtraInfo; }
  [StructLayout(LayoutKind.Explicit)]
  struct INPUTUNION { [FieldOffset(0)] public MOUSEINPUT mi; [FieldOffset(0)] public KEYBDINPUT ki; }
  [StructLayout(LayoutKind.Sequential)]
  struct INPUT { public uint type; public INPUTUNION u; }

  [DllImport("user32.dll", SetLastError = true)]
  static extern uint SendInput(uint n, INPUT[] inputs, int size);

  public static void Move(int dx, int dy) {
    var i = new INPUT { type = 0 };
    i.u.mi = new MOUSEINPUT { dx = dx, dy = dy, dwFlags = 0x0001 }; // MOUSEEVENTF_MOVE
    SendInput(1, new[] { i }, Marshal.SizeOf(typeof(INPUT)));
  }

  public static void TapShift() {
    var down = new INPUT { type = 1 };
    down.u.ki = new KEYBDINPUT { wVk = 0x10 };
    var up = new INPUT { type = 1 };
    up.u.ki = new KEYBDINPUT { wVk = 0x10, dwFlags = 0x0002 }; // KEYEVENTF_KEYUP
    SendInput(2, new[] { down, up }, Marshal.SizeOf(typeof(INPUT)));
  }
}
"@

$end = (Get-Date).AddMinutes($Minutes)
Write-Host "Sending '$Mode' input until $($end.ToString('HH:mm:ss')). Press Ctrl+C to stop."

$rounds = 0
while ((Get-Date) -lt $end) {
  if ($Mode -eq 'jiggler') {
    [FakeInput]::Move(1, 0)
    Start-Sleep -Milliseconds 20
    [FakeInput]::Move(-1, 0)
    Start-Sleep -Seconds 30
  }
  else {
    foreach ($i in 1..8) {
      [FakeInput]::Move(4, 0)
      Start-Sleep -Milliseconds 25
      [FakeInput]::Move(-4, 0)
      Start-Sleep -Milliseconds 25
    }
    $rounds++
    if ($rounds % 10 -eq 0) { [FakeInput]::TapShift() }
    Start-Sleep -Milliseconds 700
  }
}
Write-Host 'Done.'
