# Restyle – questions and decisions to confirm

Owner (8 Nov): Claude works through all partitions without stopping, picks its recommended option for every
question, notes it here and goes on. Owner answers at the end. Most important first inside each partition;
the final report sorts them across partitions.

Format: **question** – options – *chosen* and why – how easy to change later.

## P2 – Layout shell

1. **"Screen lock" in the account menu** – (a) not in the menu (before: the screen locked only by itself after some minutes), (b) "Lock screen" item always, (c) "Lock screen" only when the hotel turned the screen lock on. *Chose (c)*: you asked for it to be reachable; when the hotel has no screen lock, nobody set a PIN for it and "Lock screen" would only confuse. Super Admin never sees it. – Easy (one line).
2. **"Security" in the account menu** – the old menu had no Security item; personal security (two-step sign-in, PIN) is in My profile, the hotel's security rules are in Settings → Security. *Chose:* new item **"Sign-in security"** that opens My profile at the security cards (hidden while HotelMS support is signed in, like the cards). Hotel rules stay under Settings. – Easy.
3. **Tablet widths** – the sidebar starts as icons at 768–1023px **unless the person already chose** open/closed on that device (their choice is remembered). *Chose:* the saved choice wins, otherwise collapsed. Alternative: always start collapsed on tablets. – Easy (one line in `readSidebarOpen`).
4. **Account menu twice** (avatar top right + person at the bottom of the sidebar), like the template. – Easy to drop one.
5. **Command menu with nothing typed** shows the pages and the theme choices (template). – Easy.

## P3 – Building blocks

1. **Coloured alerts and badges** – the template's alerts and badges are mostly grey/black; ours keep soft colours (green paid, red owes, amber dirty…) in the template's shape (border, rounded-md, 12–14px). *Chose:* keep colours – staff read status by colour at the desk. – Easy (one file each).
2. **Toasts** – the template uses the `sonner` package. *Chose:* keep our own small toast list (tests, offline messages and the "money action" flow use it) and give it the sonner look (border, shadow, 14px). `sonner` stays installed but unused; removing a package needs your OK (P17). – Easy either way.
3. **Checkboxes and radio buttons** – the template uses Radix Checkbox / RadioGroup. *Chose:* restyle the native ones with CSS to look the same (box, brand colour tick). Switching to Radix would change ~45 screens' form code (business forms), which the rules forbid. – Easy to revisit per screen.
4. **Tabs** – kept our own tabs (same keyboard and screen-reader behaviour) with the template's look, instead of Radix Tabs (whose panels work differently). Tabs are now as wide as their labels (template), not the full page width. – Easy (one class).
5. **Date filter** – the template's date picker needs an extra calendar package (react-day-picker). *Chose:* keep the preset list + two date fields, restyled. – Medium (new package + install).
6. **Table header** – no grey background any more (template); rows get a light grey on hover. – Easy.
7. **Select boxes** stay native (the phone's own picker, decided in P0) with the template's look and a chevron. – Easy.

## P5 – Room board, front desk mode, charge a room

1. **Room card colours** – kept the soft room-status colours (green free, teal occupied, amber dirty, blue cleaning, grey maintenance) inside the template's card shape (rounded-xl, border, small shadow). A fully grey template card would hide the status at a glance. – Easy (`ROOM_TONES` in `lib/desk.ts`).
2. **Filter chips** – shown like the template's table filter buttons (outline, dashed until chosen, brand tint when on), 32px with a mouse / 44px on touch. – Easy.
3. **Text size** – 13px texts on screens become the template's 14px (11px → 12px). Rooms and lists are a little taller. – Easy (but touches every screen).

## P6–P11 – Walk-in, bookings, bills, guests, modules, reports

1. **Toggle buttons** (Nights / Day use / Hourly, filters, periods) – template outline buttons, filled with the brand colour when chosen. – Easy.
2. **Pickers** (room cards in walk-in / new booking, charge a room) – template card shape; the chosen one gets a brand outline and a soft tint. – Easy.
3. **Reports library** – shown like the template's settings side menu (grey = open report). – Easy.

## P12 – Settings and setup

1. **Switches** (modules, automations) – the template's small switch (18px track) in the brand colour; the tap area stays 44px on touch screens. – Easy.
2. **Settings side menu** – template settings pattern (ghost buttons, grey = open section) with the search box on top. – Easy.

## P14 – Sign-in pages

1. **Sign-in card** – template auth layout: brand row above a 480px card, title and description left-aligned (before: centred). – Easy.

## P16 – The rest

1. **404 page / screen error** – template error pages: a big "404" / "500", a short line, buttons. The "new version" error has no big number. – Easy.

## P17 – Cleanup (most of these need your decision)

1. **[Answered 8 Nov: keep for the print pages]** **Heroicons package stays** – only the three print pages still use it (the on-screen Print / paper-size buttons). You said print pages stay exactly as they are, so I did not touch them; removing the package would need those pages changed and your OK. *Recommend:* keep it until you allow a small icon change on the print pages' toolbar (not the paper). – Easy later.
2. **[Answered 8 Nov: remove – PR #104]** **`sonner` is installed but not used** (P3 kept our own toasts). *Recommend:* remove it in a later PR with your OK (removing a package needs your OK). – Easy.
3. **[Answered 8 Nov: remove – PR #104]** **`@radix-ui/react-avatar`, `-checkbox`, `-radio-group`, `-switch`, `-tabs`, `-label`, `-alert-dialog`, `-scroll-area`, `-collapsible`** are installed but not used (CSS / own components did the job without changing form code). They are not in the download (unused code is dropped by the build). *Recommend:* keep for now, or remove together with sonner. – Easy.
4. **[Done 8 Nov – PR #119]** **Staff guide pictures** (15 screenshots in `frontend/public/guide/`) still show the old look. They are made by `tools/pictures/` against a running app; I cannot run the app in my workspace. *Recommend:* after the staging test, I redo them in your browser. – Medium.
5. **Final PR `ui/shadcn-admin → main` is not opened yet** – your plan asks for a staging test first. *Recommend:* merge #88 → #103 in order into `ui/shadcn-admin`, test on staging (or locally), then I open the final PR. – —

## P19 – Collapsible filters on phones and tablets

**Pages audited** (✓ = filters now fold below 1024px):
✓ Room board (day, status, today chips, type, floor, sort, branch box) · ✓ Bookings list (branch, status) · ✓ Guests (stayed dates) · ✓ Bills (dates, status) · ✓ Payments (dates) · ✓ Cashier shifts (dates, only short) · ✓ Reports (period, compare, branch) · ✓ Activity log (dates) · ✓ Attendance report (dates) · ✓ Settings: room types, rooms (branch) · ✓ Super Admin hotels (status) · ✓ Super Admin reports, hotel health (state).
No change (nothing to fold): Staff, Roles, Devices, Shifts, Housekeeping, Approvals, Watchdog, Support inbox (only tabs = navigation); Platform activity, Docs, Help, Settings menu (search only); booking calendar and roster (week arrows = navigation, one row); front desk mode (its own big-screen layout); forms (new booking, walk-in, price preview).

1. **Collapsible without a package** – the template's Collapsible needs `@radix-ui/react-collapsible`, which #104 removed (your OK). I wrote a 70-line copy with the same names instead, so nothing needs installing. *Recommend:* keep it. If you prefer the original: `cd frontend && npm install @radix-ui/react-collapsible@1.1.12 --save-exact` and I switch the import. – Easy.
2. **Tabs that filter** (Support inbox status, Approvals status, Watchdog open/fixed, Guests "Details missing") stay visible like the room board tabs: they are short and work as navigation. *Recommend:* keep. – Easy.
3. **What counts as "on"** – only choices that differ from the page's start (e.g. bookings "Upcoming", reports "This month" and "previous period" are the start, so not counted). The search text is not a chip (the box is in view). The sort is not counted. A report's period and a settings list's branch always show as a chip without × (a report means nothing without its period). *Recommend:* keep. – Easy.
4. **"Session"** = this browser tab until it is closed (`sessionStorage`). Signing out and in again in the same tab keeps it. *Recommend:* keep; or I clear it on sign-out. – Easy.
5. **Room board branch box** folds away on the Room board tab only; on Arriving / Leaving / In house it stays (those lists have no other filters). – Easy.
6. **Small desktop change** – settings → Rooms (hotels with 2+ branches): the branch box now sits on its own line above the room-limit badge (before: same line). Everything else on desktop is unchanged. – Easy.

## QA fixes (8 Nov)

1. **[OPEN – must be done before the final PR to `main`] Licence holder** – `LICENSE` has placeholders `[OWNER FULL NAME / COMPANY NAME]` and `[OWNER EMAIL]`. Owner (8 Nov): leave them for now, fill in later. – Easy.
2. **[Answered 8 Nov: keep, if a tap opens the booking]** **Room-calendar booking bars** stay as they are; the hover card now opens only with a mouse, so a tap opens the booking (#117). – Easy.
3. **[Answered 8 Nov: yes, on every reset]** **Demo approvals** – every demo reset makes price suggestions and a refund to try (#114). – Easy.
4. **"What's new" draft** is in `docs/drafts/whats-new-new-look.md`. You publish it in Super Admin → Releases after review. – Easy.


## QA part 2 (8 Nov, evening)

1. **Branch Manager and the currency list** – Settings → Currencies opened for a Branch Manager but the list gave 403, so the exchange-rate form never showed. `permissions.md` gives Branch Managers `settings.exchange_rates` ("update exchange rates only"). *Chose:* reading the currency list also works with `settings.exchange_rates`; adding / changing / removing currencies still needs `settings.money` (test added, #119). – Easy (one route).
2. **[Done 9 Nov – #120]** **Restaurant / Bar can open Reports by its address** (not in the menu). It shows "Your role has no reports", no data. *Recommend:* show the "You can't open this page" screen instead, like other blocked screens. Not changed yet – your call. – Easy.
3. **I reset the demo hotel once** during the re-check (the two demo approvals had already been decided in part 1, so there was nothing to approve). My earlier QA test data is gone; the demo hotel id is now 16. – —

## P18 – Dashboards (9 Nov)

1. **[Done 9 Nov – answered: yes]** **"Recent payments" ignores the branch filter** – the Revenue tab's last 8 payments come from `/payments`, which has no branch option, so they show all your branches even when one branch is picked (every other widget follows the filter). *Recommend:* add `branch_id` to `GET /payments` (server, one line + test), then the list follows the filter. Needs your OK (server change). – Easy.
2. **[Answered 9 Nov: keep]** **Accountant's Reports start page** – he still opens on the Daily manager report (your answer of 8 Nov). Every other role allowed the new Overview starts there. *Recommend:* keep the Daily report for him; or he starts on the Overview too (one line). – Easy.
3. **Docker dev server and `.env`** – to answer 8 requests at once, the backend runs with `--no-reload`, so after editing `backend/.env` you run `docker compose restart backend` (in `docs/local-setup.md`). Development only. – —
4. **Charts load as one shared file** (118.3 KB gzip) the first time any dashboard screen opens, then they are reused. *Recommend:* keep (one download, cached). – —
