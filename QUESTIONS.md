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

1. **Heroicons package stays** – only the three print pages still use it (the on-screen Print / paper-size buttons). You said print pages stay exactly as they are, so I did not touch them; removing the package would need those pages changed and your OK. *Recommend:* keep it until you allow a small icon change on the print pages' toolbar (not the paper). – Easy later.
2. **`sonner` is installed but not used** (P3 kept our own toasts). *Recommend:* remove it in a later PR with your OK (removing a package needs your OK). – Easy.
3. **`@radix-ui/react-avatar`, `-checkbox`, `-radio-group`, `-switch`, `-tabs`, `-label`, `-alert-dialog`, `-scroll-area`, `-collapsible`** are installed but not used (CSS / own components did the job without changing form code). They are not in the download (unused code is dropped by the build). *Recommend:* keep for now, or remove together with sonner. – Easy.
4. **Staff guide pictures** (15 screenshots in `frontend/public/guide/`) still show the old look. They are made by `tools/pictures/` against a running app; I cannot run the app in my workspace. *Recommend:* after the staging test, I redo them in your browser. – Medium.
5. **Final PR `ui/shadcn-admin → main` is not opened yet** – your plan asks for a staging test first. *Recommend:* merge #88 → #103 in order into `ui/shadcn-admin`, test on staging (or locally), then I open the final PR. – —
