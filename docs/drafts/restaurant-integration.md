# Restaurant: deeper integration (proposal only – not built)

Today (owner, 14 Nov 2026): the hotel sets a **menu link** (Settings → Guest messages → Guest information). It shows on the
booking page, in the welcome message and on the printed room QR cards. Room charges from the restaurant already work with
"Charge a room" (Restaurant / Bar role).

Ideas for later, from small to big (each one is optional and needs the owner's yes):

| # | Idea | What it needs | Rough size |
|---|---|---|---|
| R1 | **Menu inside HotelMS**: items, prices, photos, EN/AM, categories; a public menu page per hotel (no app, fast like the booking page); the QR opens it | menu tables, a public Blade page, Settings screen | 1–2 PRs |
| R2 | **Order to the room** from the menu page (guest scans the room card): the order goes to the restaurant screen, then "Charge room" with one tap | guest ordering page with the room's secret code, a restaurant order list (polling, no WebSocket) | 2 PRs |
| R3 | **Restaurant screen (POS-light)**: open tables, add items, split, pay cash/telebirr or charge a room; shift close like the cashier | orders, tables, kitchen ticket print (80 mm) | 3–4 PRs |
| R4 | **Stock and reports**: items sold, best sellers, cost of goods, daily restaurant report next to rooms | stock tables, reports | 2 PRs |

Recommendation: start with R1 (menu inside HotelMS) – it replaces the outside link, works offline-light, and is the base for R2.
Plans: R1 every plan; R2–R4 Pro and Business.
