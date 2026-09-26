//! Windows 10/11 implementation using Win32 APIs.

use std::collections::HashMap;
use std::ffi::c_void;
use std::sync::mpsc::Sender;
use std::sync::{Mutex, OnceLock};
use std::time::{Duration, Instant};

use windows::core::{w, BOOL, PCWSTR, PWSTR};
use windows::Win32::Foundation::{CloseHandle, HWND, LPARAM, LRESULT, WPARAM};
use windows::Win32::Storage::FileSystem::{
    GetFileVersionInfoSizeW, GetFileVersionInfoW, VerQueryValueW,
};
use windows::Win32::System::LibraryLoader::GetModuleHandleW;
use windows::Win32::System::Diagnostics::ToolHelp::{
    CreateToolhelp32Snapshot, Process32FirstW, Process32NextW, PROCESSENTRY32W, TH32CS_SNAPPROCESS,
};
use windows::Win32::UI::Input::{
    GetRawInputData, RegisterRawInputDevices, HRAWINPUT, RAWINPUT, RAWINPUTDEVICE, RAWINPUTHEADER, RIDEV_INPUTSINK,
    RID_INPUT,
};
use windows::Win32::System::Registry::{RegGetValueW, HKEY_LOCAL_MACHINE, RRF_RT_REG_SZ};
use windows::Win32::System::RemoteDesktop::{
    WTSRegisterSessionNotification, NOTIFY_FOR_THIS_SESSION,
};
use windows::Win32::System::SystemInformation::GetTickCount;
use windows::Win32::System::Threading::{
    OpenProcess, QueryFullProcessImageNameW, PROCESS_NAME_WIN32, PROCESS_QUERY_LIMITED_INFORMATION,
};
use windows::Win32::UI::Input::KeyboardAndMouse::{GetLastInputInfo, LASTINPUTINFO};
use windows::Win32::UI::WindowsAndMessaging::{
    CreateWindowExW, DefWindowProcW, DispatchMessageW, EnumChildWindows, GetClassNameW,
    GetForegroundWindow, GetMessageW, GetWindowTextW, GetWindowThreadProcessId, RegisterClassW,
    TranslateMessage, MSG, PBT_APMRESUMEAUTOMATIC, WM_INPUT, PBT_APMSUSPEND, WINDOW_EX_STYLE, WM_ENDSESSION,
    WM_POWERBROADCAST, WM_WTSSESSION_CHANGE, WNDCLASSW, WS_OVERLAPPED, WTS_SESSION_LOCK,
    WTS_SESSION_UNLOCK,
};

use super::input::{InputCollector, InputStats};
use super::{ActivityProvider, ForegroundApp, SystemEvent};

const MAX_TITLE_CHARS: usize = 512;

/// Where the raw input window puts what it counts (the activity check).
static COLLECTOR: OnceLock<InputCollector> = OnceLock::new();
static CLOCK_START: OnceLock<Instant> = OnceLock::new();

fn collector() -> &'static InputCollector {
    COLLECTOR.get_or_init(InputCollector::new)
}

/// Milliseconds on a steady clock, for the gaps between mouse moves.
fn steady_ms() -> u64 {
    CLOCK_START.get_or_init(Instant::now).elapsed().as_millis() as u64
}

struct WindowsProvider {
    /// exe path → friendly name. Reading version info is slow-ish, so cache it.
    names: Mutex<HashMap<String, String>>,
    /// What was seen at the previous look at the system's last-input time.
    sample: Mutex<SoftwareOnlySample>,
}

#[derive(Default)]
struct SoftwareOnlySample {
    last_input_tick: u32,
    last_at: Option<Instant>,
    hardware_two_ago: u64,
    hardware_before: u64,
}

pub fn provider() -> Box<dyn ActivityProvider> {
    Box::new(WindowsProvider {
        names: Mutex::new(HashMap::new()),
        sample: Mutex::new(SoftwareOnlySample::default()),
    })
}

impl ActivityProvider for WindowsProvider {
    fn take_input_stats(&self) -> Option<InputStats> {
        Some(collector().take())
    }

    fn set_input_capture(&self, on: bool) {
        collector().set_enabled(on);
    }

    fn current_activity(&self) -> Option<ForegroundApp> {
        let hwnd = unsafe { GetForegroundWindow() };
        if hwnd.is_invalid() {
            // Happens on the UAC secure desktop and briefly while switching.
            return Some(named("Windows security prompt", "", ""));
        }

        let title = window_text(hwnd);
        let pid = window_pid(hwnd);
        let Some(mut path) = process_path(pid) else {
            // Usually an elevated (admin) process we aren't allowed to inspect.
            return Some(named("Protected app", "", &title));
        };
        let mut exe = file_name(&path).to_string();

        // Store apps (Calculator, Settings) run inside ApplicationFrameHost.
        // The real app is a child window owned by another process, and the
        // window title is the most reliable friendly name.
        if exe.eq_ignore_ascii_case("ApplicationFrameHost.exe") {
            if let Some(child_path) = uwp_child_pid(hwnd, pid).and_then(process_path) {
                path = child_path;
                exe = file_name(&path).to_string();
            }
            let app_name = if title.is_empty() {
                self.friendly_name(&path, &exe)
            } else {
                title.clone()
            };
            return Some(named(&app_name, &exe, &title));
        }

        if exe.eq_ignore_ascii_case("explorer.exe") {
            let app_name = match class_name(hwnd).as_str() {
                "Progman" | "WorkerW" => "Desktop",
                "Shell_TrayWnd" | "Shell_SecondaryTrayWnd" => "Taskbar",
                _ => "File Explorer",
            };
            return Some(named(app_name, &exe, &title));
        }

        if exe.eq_ignore_ascii_case("LockApp.exe") {
            return Some(named("Lock screen", &exe, ""));
        }

        let app_name = self.friendly_name(&path, &exe);
        Some(named(&app_name, &exe, &title))
    }

    fn idle_seconds(&self) -> u64 {
        let mut info = LASTINPUTINFO {
            cbSize: std::mem::size_of::<LASTINPUTINFO>() as u32,
            dwTime: 0,
        };
        if !unsafe { GetLastInputInfo(&mut info) }.as_bool() {
            return 0;
        }
        self.sample_software_only(info.dwTime);
        // Both values are 32-bit tick counts that wrap every ~49 days.
        let now = unsafe { GetTickCount() };
        u64::from(now.wrapping_sub(info.dwTime) / 1000)
    }
}

impl WindowsProvider {
    /// The system's last-input time moved, yet no hardware event arrived for two looks in a row: the activity came from
    /// software (a script sent it). Not judged while the window in front cannot be read, because a program running with
    /// more rights than this one also hides its input from us.
    fn sample_software_only(&self, last_input_tick: u32) {
        let now = Instant::now();
        let hardware_now = collector().hardware_total();
        let mut s = self.sample.lock().unwrap_or_else(|e| e.into_inner());
        let Some(last) = s.last_at else {
            s.last_at = Some(now);
            s.last_input_tick = last_input_tick;
            s.hardware_before = hardware_now;
            s.hardware_two_ago = hardware_now;
            return;
        };
        let elapsed = now.duration_since(last);
        if elapsed < Duration::from_secs(1) {
            return; // looked a moment ago already
        }
        if last_input_tick != s.last_input_tick && hardware_now == s.hardware_two_ago && foreground_readable() {
            collector().note_software_only(elapsed.as_secs().min(5));
        }
        s.hardware_two_ago = s.hardware_before;
        s.hardware_before = hardware_now;
        s.last_input_tick = last_input_tick;
        s.last_at = Some(now);
    }

    fn friendly_name(&self, path: &str, exe: &str) -> String {
        let mut names = self.names.lock().unwrap_or_else(|e| e.into_inner());
        names
            .entry(path.to_string())
            .or_insert_with(|| {
                file_description(path)
                    .filter(|d| !d.is_empty())
                    .unwrap_or_else(|| strip_exe(exe).to_string())
            })
            .clone()
    }
}

/// Whether the window in front belongs to a process this one may look at.
fn foreground_readable() -> bool {
    let hwnd = unsafe { GetForegroundWindow() };
    if hwnd.0.is_null() {
        return true;
    }
    process_path(window_pid(hwnd)).is_some()
}

fn named(app_name: &str, process_name: &str, window_title: &str) -> ForegroundApp {
    ForegroundApp {
        app_name: app_name.to_string(),
        process_name: process_name.to_string(),
        window_title: window_title.to_string(),
    }
}

fn file_name(path: &str) -> &str {
    path.rsplit(['\\', '/']).next().unwrap_or(path)
}

fn strip_exe(exe: &str) -> &str {
    if exe.len() > 4 && exe[exe.len() - 4..].eq_ignore_ascii_case(".exe") {
        &exe[..exe.len() - 4]
    } else {
        exe
    }
}

fn to_wide(s: &str) -> Vec<u16> {
    s.encode_utf16().chain(std::iter::once(0)).collect()
}

fn window_text(hwnd: HWND) -> String {
    let mut buf = [0u16; MAX_TITLE_CHARS];
    let len = unsafe { GetWindowTextW(hwnd, &mut buf) };
    String::from_utf16_lossy(&buf[..len.max(0) as usize])
        .trim()
        .to_string()
}

fn class_name(hwnd: HWND) -> String {
    let mut buf = [0u16; 256];
    let len = unsafe { GetClassNameW(hwnd, &mut buf) };
    String::from_utf16_lossy(&buf[..len.max(0) as usize])
}

fn window_pid(hwnd: HWND) -> u32 {
    let mut pid = 0u32;
    unsafe { GetWindowThreadProcessId(hwnd, Some(&mut pid)) };
    pid
}

fn process_path(pid: u32) -> Option<String> {
    if pid == 0 {
        return None;
    }
    let handle = unsafe { OpenProcess(PROCESS_QUERY_LIMITED_INFORMATION, false, pid) }.ok()?;
    let mut buf = [0u16; 1024];
    let mut len = buf.len() as u32;
    let result = unsafe {
        QueryFullProcessImageNameW(
            handle,
            PROCESS_NAME_WIN32,
            PWSTR(buf.as_mut_ptr()),
            &mut len,
        )
    };
    let _ = unsafe { CloseHandle(handle) };
    result.ok()?;
    Some(String::from_utf16_lossy(&buf[..len as usize]))
}

struct ChildSearch {
    host_pid: u32,
    found_pid: u32,
}

/// Finds the first child window owned by a process other than the host.
fn uwp_child_pid(hwnd: HWND, host_pid: u32) -> Option<u32> {
    unsafe extern "system" fn visit(child: HWND, lparam: LPARAM) -> BOOL {
        let search = unsafe { &mut *(lparam.0 as *mut ChildSearch) };
        let pid = window_pid(child);
        if pid != 0 && pid != search.host_pid {
            search.found_pid = pid;
            return BOOL(0); // stop
        }
        BOOL(1)
    }

    let mut search = ChildSearch {
        host_pid,
        found_pid: 0,
    };
    unsafe {
        let _ = EnumChildWindows(
            Some(hwnd),
            Some(visit),
            LPARAM(&mut search as *mut ChildSearch as isize),
        );
    }
    (search.found_pid != 0).then_some(search.found_pid)
}

/// Reads the "FileDescription" from the exe's version info,
/// e.g. Code.exe → "Visual Studio Code".
fn file_description(path: &str) -> Option<String> {
    let wide_path = to_wide(path);
    let size = unsafe { GetFileVersionInfoSizeW(PCWSTR(wide_path.as_ptr()), None) };
    if size == 0 {
        return None;
    }
    let mut data = vec![0u8; size as usize];
    unsafe {
        GetFileVersionInfoW(
            PCWSTR(wide_path.as_ptr()),
            None,
            size,
            data.as_mut_ptr() as *mut c_void,
        )
    }
    .ok()?;

    let mut ptr: *mut c_void = std::ptr::null_mut();
    let mut len = 0u32;

    // Use the file's first language/codepage, falling back to US English + Unicode.
    let has_translation = unsafe {
        VerQueryValueW(
            data.as_ptr() as *const c_void,
            w!("\\VarFileInfo\\Translation"),
            &mut ptr,
            &mut len,
        )
    }
    .as_bool();
    let (lang, codepage) = if has_translation && len >= 4 && !ptr.is_null() {
        let pair = unsafe { std::slice::from_raw_parts(ptr as *const u16, 2) };
        (pair[0], pair[1])
    } else {
        (0x0409, 0x04B0)
    };

    let key = to_wide(&format!(
        "\\StringFileInfo\\{lang:04x}{codepage:04x}\\FileDescription"
    ));
    let found = unsafe {
        VerQueryValueW(
            data.as_ptr() as *const c_void,
            PCWSTR(key.as_ptr()),
            &mut ptr,
            &mut len,
        )
    }
    .as_bool();
    if !found || len == 0 || ptr.is_null() {
        return None;
    }
    let chars = unsafe { std::slice::from_raw_parts(ptr as *const u16, len as usize) };
    let text = String::from_utf16_lossy(chars);
    Some(text.trim_end_matches('\0').trim().to_string())
}

// ---------------------------------------------------------------------------
// System events (lock / unlock / sleep / wake / shutdown)
// ---------------------------------------------------------------------------

static EVENT_TX: OnceLock<Sender<SystemEvent>> = OnceLock::new();

/// What Windows says about the computer: manufacturer, model and BIOS vendor from the registry, the CPU's hypervisor bit,
/// and whether this is a Remote Desktop session. Reading these needs no special rights.
pub fn environment_facts() -> super::environment::Facts {
    use windows::Win32::UI::WindowsAndMessaging::{GetSystemMetrics, SM_REMOTESESSION};

    let read = |name: PCWSTR| registry_text(w!(r"HARDWARE\DESCRIPTION\System\BIOS"), name);
    super::environment::Facts {
        manufacturer: read(w!("SystemManufacturer")),
        product: read(w!("SystemProductName")),
        bios_vendor: read(w!("BIOSVendor")),
        hypervisor_present: hypervisor_bit(),
        remote_session: unsafe { GetSystemMetrics(SM_REMOTESESSION) } != 0,
    }
}

/// A text value under HKEY_LOCAL_MACHINE, empty when it is missing.
fn registry_text(subkey: PCWSTR, value: PCWSTR) -> String {
    let mut buffer = [0u16; 256];
    let mut bytes = (buffer.len() * 2) as u32;
    let status = unsafe {
        RegGetValueW(
            HKEY_LOCAL_MACHINE,
            subkey,
            value,
            RRF_RT_REG_SZ,
            None,
            Some(buffer.as_mut_ptr() as *mut c_void),
            Some(&mut bytes),
        )
    };
    if status.0 != 0 {
        return String::new();
    }
    let len = buffer.iter().position(|&c| c == 0).unwrap_or(buffer.len());
    String::from_utf16_lossy(&buffer[..len]).trim().to_owned()
}

#[cfg(target_arch = "x86_64")]
fn hypervisor_bit() -> bool {
    // CPUID leaf 1, ECX bit 31: "running under a hypervisor"
    std::arch::x86_64::__cpuid(1).ecx & (1 << 31) != 0
}

#[cfg(not(target_arch = "x86_64"))]
fn hypervisor_bit() -> bool {
    false
}

/// Counts one raw input message. Only where the input came from is looked at: real hardware has a device handle, input
/// sent by software has none. Which key was pressed is never read.
fn count_raw_input(lparam: LPARAM) {
    // room for the largest mouse or keyboard message
    let mut buffer = [0u64; 64];
    let mut size = std::mem::size_of_val(&buffer) as u32;
    let header_size = std::mem::size_of::<RAWINPUTHEADER>() as u32;
    let read = unsafe {
        GetRawInputData(
            HRAWINPUT(lparam.0 as *mut c_void),
            RID_INPUT,
            Some(buffer.as_mut_ptr() as *mut c_void),
            &mut size,
            header_size,
        )
    };
    if read == u32::MAX || (read as usize) < std::mem::size_of::<RAWINPUTHEADER>() {
        return;
    }
    let raw = unsafe { &*(buffer.as_ptr() as *const RAWINPUT) };
    let hardware = !raw.header.hDevice.is_invalid();
    let input = collector();
    match raw.header.dwType {
        // RIM_TYPEMOUSE
        0 => {
            let mouse = unsafe { raw.data.mouse };
            let buttons = unsafe { mouse.Anonymous.Anonymous.usButtonFlags } as u32;
            // left, right or middle button went down
            if buttons & (0x0001 | 0x0004 | 0x0010) != 0 {
                input.record_click(hardware);
            }
            if mouse.usFlags.0 & 0x01 != 0 {
                input.record_absolute_move(hardware, mouse.lLastX, mouse.lLastY, steady_ms());
            } else if mouse.lLastX != 0 || mouse.lLastY != 0 {
                input.record_move(hardware, mouse.lLastX, mouse.lLastY, steady_ms());
            }
        }
        // RIM_TYPEKEYBOARD: only key-down messages are counted (bit 0 of Flags is "break", key up)
        1 => {
            let keyboard = unsafe { raw.data.keyboard };
            if keyboard.Flags & 0x01 == 0 {
                input.record_key(hardware);
            }
        }
        _ => {}
    }
}

/// The names of the running processes (only compared with the known-macro list, never sent as they are).
pub fn running_process_names() -> Vec<String> {
    let mut names = Vec::new();
    let Ok(snapshot) = (unsafe { CreateToolhelp32Snapshot(TH32CS_SNAPPROCESS, 0) }) else {
        return names;
    };
    let mut entry = PROCESSENTRY32W {
        dwSize: std::mem::size_of::<PROCESSENTRY32W>() as u32,
        ..Default::default()
    };
    if unsafe { Process32FirstW(snapshot, &mut entry) }.is_ok() {
        loop {
            let len = entry.szExeFile.iter().position(|&c| c == 0).unwrap_or(entry.szExeFile.len());
            names.push(String::from_utf16_lossy(&entry.szExeFile[..len]));
            if unsafe { Process32NextW(snapshot, &mut entry) }.is_err() {
                break;
            }
        }
    }
    let _ = unsafe { CloseHandle(snapshot) };
    names
}

pub fn start_system_events(tx: Sender<SystemEvent>) {
    if EVENT_TX.set(tx).is_err() {
        return; // already running
    }
    std::thread::Builder::new()
        .name("system-events".into())
        .spawn(|| {
            if let Err(e) = unsafe { run_event_window() } {
                eprintln!("system event window failed: {e}");
            }
        })
        .expect("failed to spawn system-events thread");
}

/// A hidden top-level window. (A message-only window would not receive
/// WM_POWERBROADCAST or WM_ENDSESSION, which are only sent to top-level windows.)
unsafe fn run_event_window() -> windows::core::Result<()> {
    let instance = unsafe { GetModuleHandleW(None) }?;
    let class = w!("TimeTrackerSystemEvents");
    let wc = WNDCLASSW {
        lpfnWndProc: Some(event_wndproc),
        hInstance: instance.into(),
        lpszClassName: class,
        ..Default::default()
    };
    unsafe { RegisterClassW(&wc) };

    let hwnd = unsafe {
        CreateWindowExW(
            WINDOW_EX_STYLE(0),
            class,
            w!("Time Tracker system events"),
            WS_OVERLAPPED,
            0,
            0,
            0,
            0,
            None,
            None,
            Some(instance.into()),
            None,
        )
    }?;
    unsafe { WTSRegisterSessionNotification(hwnd, NOTIFY_FOR_THIS_SESSION) }?;
    // raw mouse and keyboard input, for the activity check: counts only, wherever the focus is
    let devices = [
        RAWINPUTDEVICE { usUsagePage: 0x01, usUsage: 0x02, dwFlags: RIDEV_INPUTSINK, hwndTarget: hwnd },
        RAWINPUTDEVICE { usUsagePage: 0x01, usUsage: 0x06, dwFlags: RIDEV_INPUTSINK, hwndTarget: hwnd },
    ];
    if unsafe { RegisterRawInputDevices(&devices, std::mem::size_of::<RAWINPUTDEVICE>() as u32) }.is_err() {
        eprintln!("raw input could not be registered: the activity check has no input statistics");
    }

    let mut msg = MSG::default();
    while unsafe { GetMessageW(&mut msg, None, 0, 0) }.as_bool() {
        unsafe {
            let _ = TranslateMessage(&msg);
            DispatchMessageW(&msg);
        }
    }
    Ok(())
}

unsafe extern "system" fn event_wndproc(
    hwnd: HWND,
    msg: u32,
    wparam: WPARAM,
    lparam: LPARAM,
) -> LRESULT {
    if msg == WM_INPUT {
        count_raw_input(lparam);
    }
    let event = match msg {
        WM_WTSSESSION_CHANGE => match wparam.0 as u32 {
            WTS_SESSION_LOCK => Some(SystemEvent::Lock),
            WTS_SESSION_UNLOCK => Some(SystemEvent::Unlock),
            _ => None,
        },
        WM_POWERBROADCAST => match wparam.0 as u32 {
            PBT_APMSUSPEND => Some(SystemEvent::Sleep),
            PBT_APMRESUMEAUTOMATIC => Some(SystemEvent::Wake),
            _ => None,
        },
        WM_ENDSESSION if wparam.0 != 0 => Some(SystemEvent::Shutdown),
        _ => None,
    };
    if let (Some(event), Some(tx)) = (event, EVENT_TX.get()) {
        let _ = tx.send(event);
    }
    unsafe { DefWindowProcW(hwnd, msg, wparam, lparam) }
}

/// Real Windows input: these send input the way a macro program does and check that it is told from hardware. They need
/// an interactive desktop, so they only run on request: `cargo test --lib live_input -- --ignored --nocapture`.
#[cfg(test)]
mod live_input {
    use super::*;
    use std::time::Duration;
    use windows::Win32::UI::Input::KeyboardAndMouse::{
        SendInput, INPUT, INPUT_0, INPUT_KEYBOARD, INPUT_MOUSE, KEYBDINPUT, KEYEVENTF_KEYUP, MOUSEEVENTF_MOVE, MOUSEINPUT,
        VIRTUAL_KEY,
    };

    fn send(inputs: &[INPUT]) {
        unsafe { SendInput(inputs, std::mem::size_of::<INPUT>() as i32) };
    }

    fn mouse_step(dx: i32) -> INPUT {
        INPUT {
            r#type: INPUT_MOUSE,
            Anonymous: INPUT_0 { mi: MOUSEINPUT { dx, dy: 0, dwFlags: MOUSEEVENTF_MOVE, ..Default::default() } },
        }
    }

    fn key(vk: u16, up: bool) -> INPUT {
        INPUT {
            r#type: INPUT_KEYBOARD,
            Anonymous: INPUT_0 {
                ki: KEYBDINPUT {
                    wVk: VIRTUAL_KEY(vk),
                    dwFlags: if up { KEYEVENTF_KEYUP } else { Default::default() },
                    ..Default::default()
                },
            },
        }
    }

    #[test]
    #[ignore = "needs an interactive desktop"]
    fn input_sent_by_software_is_counted_as_software_and_the_system_sees_it_as_activity() {
        let (tx, _rx) = std::sync::mpsc::channel();
        start_system_events(tx);
        std::thread::sleep(Duration::from_millis(500));
        let provider = provider();
        let _ = collector().take();

        // one look, then software input for a few seconds, looking once a second like the engine does
        let _ = provider.idle_seconds();
        for _ in 0..4 {
            std::thread::sleep(Duration::from_millis(1100));
            for _ in 0..10 {
                send(&[mouse_step(1)]);
                send(&[mouse_step(-1)]);
            }
            send(&[key(0x10, false), key(0x10, true)]); // shift down and up
            std::thread::sleep(Duration::from_millis(100));
            let _ = provider.idle_seconds();
        }

        let stats = provider.take_input_stats().unwrap();
        eprintln!("live counts: {stats:?}");
        assert!(stats.sw_mouse >= 60, "software mouse moves were {}", stats.sw_mouse);
        assert!(stats.sw_keys >= 3, "software key presses were {}", stats.sw_keys);
        assert!(stats.sw_only_seconds >= 1, "software-only seconds were {}", stats.sw_only_seconds);
    }
}
