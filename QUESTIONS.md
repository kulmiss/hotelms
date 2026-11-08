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
