//! Windows 10/11 implementation using Win32 APIs.

use std::collections::HashMap;
use std::ffi::c_void;
use std::sync::mpsc::Sender;
use std::sync::{Mutex, OnceLock};

use windows::core::{w, BOOL, PCWSTR, PWSTR};
use windows::Win32::Foundation::{CloseHandle, HWND, LPARAM, LRESULT, WPARAM};
use windows::Win32::Storage::FileSystem::{
    GetFileVersionInfoSizeW, GetFileVersionInfoW, VerQueryValueW,
};
use windows::Win32::System::LibraryLoader::GetModuleHandleW;
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
    TranslateMessage, MSG, PBT_APMRESUMEAUTOMATIC, PBT_APMSUSPEND, WINDOW_EX_STYLE, WM_ENDSESSION,
    WM_POWERBROADCAST, WM_WTSSESSION_CHANGE, WNDCLASSW, WS_OVERLAPPED, WTS_SESSION_LOCK,
    WTS_SESSION_UNLOCK,
};

use super::{ActivityProvider, ForegroundApp, SystemEvent};

const MAX_TITLE_CHARS: usize = 512;

struct WindowsProvider {
    /// exe path → friendly name. Reading version info is slow-ish, so cache it.
    names: Mutex<HashMap<String, String>>,
}

pub fn provider() -> Box<dyn ActivityProvider> {
    Box::new(WindowsProvider {
        names: Mutex::new(HashMap::new()),
    })
}

impl ActivityProvider for WindowsProvider {
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
        // Both values are 32-bit tick counts that wrap every ~49 days.
        let now = unsafe { GetTickCount() };
        u64::from(now.wrapping_sub(info.dwTime) / 1000)
    }
}

impl WindowsProvider {
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
