# Setup

## Desktop app on Windows (Phase 0)

The desktop app only works on **Windows 10 or 11**. Do these steps once on the Windows PC.

### 1. Install the tools

1. **Git:** https://git-scm.com/download/win (default options).
2. **Node.js 24 LTS:** https://nodejs.org (default options).
3. **pnpm:** open PowerShell and run:
   ```powershell
   corepack enable
   corepack prepare pnpm@12.4.1 --activate
   ```
4. **Microsoft C++ Build Tools:** https://visualstudio.microsoft.com/visual-cpp-build-tools/
   In the installer, tick **"Desktop development with C++"** and install.
5. **Rust:** https://rustup.rs → download `rustup-init.exe` → run it → press Enter for the defaults.
6. **WebView2:** already included in Windows 10/11. Nothing to do.

Close and reopen PowerShell after installing, so the new commands are found.

### 2. Get the code and run it

```powershell
git clone <repo-url> time-tracker
cd time-tracker
pnpm install
pnpm dev:agent
```

The first run compiles the Rust code and takes a few minutes. Later runs are fast.
A window called **Time Tracker** opens and shows the app in front, the window title and the idle seconds.

### 3. Phase 0 background log

The app writes a line every 2 seconds, plus every lock/sleep event, to:

```text
%LOCALAPPDATA%\com.office.timetracker\logs\activity-spike.log
```

(The exact path is also shown at the bottom of the app window.) Use it for tests 0.7 and 0.8 in `docs/DEVELOPMENT_PLAN.md`.

### 4. Build an installer (optional in Phase 0)

```powershell
pnpm build:agent
```

The installer is created in `apps/agent/src-tauri/target/release/bundle/nsis/`.
Use the installed (release) build for test 0.11 (resource use). Dev builds use more CPU and memory.
