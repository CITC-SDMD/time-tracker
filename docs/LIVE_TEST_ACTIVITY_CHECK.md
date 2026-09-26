# Live test: the activity check

The rules (docs/DEVELOPMENT_PLAN.md §16) are tested by the PHP suite and the browser tests, and the counting of software input by an automatic test on Windows. This is the by-hand check with the real desktop app on a real PC: it shows what a manager will actually see. Write the results in the table at the end and keep the thresholds in `apps/api/config/integrity.php` honest with what you learn.

## What you need
- The server and dashboard running (development: API on 8000, dashboard on 3100), and the desktop app rebuilt with this version (`pnpm dev:agent`, or an installer built with `pnpm build:agent`). An old build does not send the input counts.
- Two accounts in the same organization: **Admin** (sees the flags) and the **person being tested**. A superadmin who opened the office also sees them.
- The organization's screenshot interval on (Settings) so you can also check the screenshot evidence.
- The script `scripts/live-test/simulate-input.ps1`. It needs nothing installed, never clicks and never types a letter (only taps Shift).

Each part of a day is judged in 10 minute chunks, and a flag needs enough chunks (20 minutes for software input, 60 for a robotic pattern). To see results faster while testing, temporarily lower `min_minutes` in `config/integrity.php` on the **development** server, run `php artisan tracker:reevaluate-days`, and put the numbers back afterwards.

## Test 1: a normal working day (expect no flag)
1. Sign in to the desktop app as the test person and start tracking.
2. Work for at least 30 minutes with the real keyboard and mouse: write, browse, switch between windows.
3. Stop, wait for "All data sent", then open the person's day on the dashboard as Admin.

**Expected:** no "Review activity" or "Automation likely" badge and no "Activity check" card. If a flag appears, write down the reason it gave. That is a false positive and the most important thing this test can find.

## Test 2: a macro (expect Automation likely)
1. Start tracking. Leave the mouse and keyboard alone.
2. In PowerShell: `powershell -ExecutionPolicy Bypass -File scripts\live-test\simulate-input.ps1 -Mode macro -Minutes 30`
3. When it ends, stop tracking, wait for the sync, and open the day as Admin.

**Expected:** "Automation likely" with the reasons "Most mouse and keyboard input ... was sent by software" and "The computer registered activity ... while no input arrived from a keyboard or mouse". The tracked hours are unchanged.

## Test 3: a jiggler pattern (expect Review or Automation likely)
1. Start tracking, hands off.
2. Run the script with `-Mode jiggler -Minutes 70`.

**Expected:** software input flags as in test 2, plus "the mouse moved in tiny, perfectly regular steps" once 60 minutes are covered. This script cannot copy a **USB jiggler**, which Windows sees as a real mouse: with one, only the pattern reason ("tiny, perfectly regular steps, no clicking or typing") can appear, not the software reasons. If you have a real one, run it for 70 minutes and record which reasons show.

## Test 4: a known macro program (expect Review)
1. Install AutoHotkey (or start any program from the list in `config/integrity.php`), start tracking and leave the program running for a few minutes.
2. Open the day as Admin.

**Expected:** "A program made to automate input was running while tracking: autohotkey". Then close the program and check that the names of your other programs (for example your editor) were never sent: in the database, `daily_summaries.macro_tools` holds only names from the list.

## Test 5: remote-control and accessibility software (expect at most Review)
Run the same software input over a Remote Desktop session or a virtual machine (the day also shows the "Remote session" or "Virtual machine" badge), and use dictation or a screen reader for 20 minutes.

**Expected:** at most "Review activity", and the reason carries the sentence "This can also come from remote-control or accessibility software."

## Test 6: the switch
As a superadmin with the permission, open the person in the office and switch detection off. **Expected:** the badge and card disappear, the audit log shows "Switched the virtual machine detection on or off for a person", and after the next sync the person's new days have no counts.

## Test 7: real hardware is counted as hardware
Track for 10 minutes with the real keyboard and mouse only. In the database (`sessions.input_stats`) the new rows should show `hwKeys` and `hwMouse` well above zero and `swKeys`/`swMouse` at or near zero. If hardware input shows up as software here, stop and tell the developer: it would flag honest people.

## Test 8: elevated windows and the machine
- Open an administrator window (an elevated terminal or Task Manager) and work in it with the real keyboard for 20 minutes. **Expected:** no "activity without hardware input" reason. (The app skips that check while it cannot read the window in front.)
- Watch CPU and memory of the installed app for an hour: it should stay in the same range as before. Check the antivirus did not quarantine the app or warn about "keyboard monitoring".

## Results

| Test | Date | Result (flag and reasons seen) | Matches expected? | Notes |
|---|---|---|---|---|
| 1 normal day | | | | |
| 2 macro | | | | |
| 3 jiggler pattern | | | | |
| 4 known program | | | | |
| 5 remote and accessibility | | | | |
| 6 switch | | | | |
| 7 hardware counted as hardware | | | | |
| 8 elevated windows, resources, antivirus | | | | |
