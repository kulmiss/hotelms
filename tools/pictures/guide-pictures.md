# Staff-guide pictures (15) – how to remake them

All pictures: **demo hotel**, **light mode**, browser view **1100 × 860 px** (desktop layout, so the room-board chips
show), banners hidden, animations off. Super Admin → "log in as hotel" with the reason *"Staff guide pictures (owner
request)"*. The orange numbers are the **step numbers** in `frontend/src/i18n/locales/en.guide.json`.

Steps per picture:
1. Paste `browser-helpers.js` into the page (it defines `T()`, `__prep()`, `__marks()`).
2. Run the "marks" line below: it opens the screen and returns the boxes as JSON. Save it as `/tmp/guide-pics/<key>.marks.json`.
3. Take a screenshot (scale 1) and note its width in pixels (the "coordinate frame" width).
4. `python3 tools/pictures/make_spec.py <key> /tmp/guide-pics/<key>.marks.json <screenshot> <width>` → writes `frontend/public/guide/<key>.webp`.

| # | key | Who (demo) | Screen | Demo data needed | Marks (step → element) |
|---|---|---|---|---|---|
| 1 | roomBoard | Reception (Hana) | `/rooms` | any day | 1 menu "Room board" · 3 status chip "Dirty" · 4 chips Arriving / Leaving / Overdue / Free tonight · 5 an "Owes" tag · 6 a room box · 7 day picker |
| 2 | walkIn | Reception | `/rooms/walk-in` | a free room | 1 page title · 2 Nights / Day use / Hourly + check-out · 4 room list · 6 Summary card · 8 "Take payment and check in" |
| 3 | freeRooms | Reception | `/reservations?view=calendar` | bookings in the next 14 days | 1 "Calendar" tab · 2 a booking bar · 3 an empty cell · 4 a day header |
| 4 | checkIn | Reception | `/reservations/<arrival today>` | a confirmed arrival today | 2 "Check in" button · 5 "Record payment" |
| 5 | moveExtend | Reception | `/reservations/<in house>` | a guest in house | 1 "Move / extend" button |
| 6 | checkOut | Reception | `/reservations/<leaving today>` | a guest leaving today | 2 "Add charge" · 4 balance / To pay · 6 "Check out" |
| 7 | changeBooking | Reception | `/reservations/<future booking>` | a confirmed future booking | 1 "Edit" button |
| 8 | cancelBooking | Reception | `/reservations/<future booking>` | same | 1 "Cancel booking" button |
| 9 | noShow | Reception | `/reservations/<late arrival>` | an arrival marked Late | 2 "Mark no-show" |
| 10 | takePayment | Reception | `/billing/<open bill>` → tap Record payment | a partly paid bill | 1 "Record payment" · 2 method · 3 amount + reference · 4 "Save payment" |
| 11 | paymentProof | Reception | same panel | same | 2 Take photo / Choose file · 3 "Add proof" on a payment |
| 12 | extraCharge | Reception | `/billing/<open bill>` → tap Add charge | same | 2 "Add charge" · 3 charge type · 4 quantity + price + Add charge |
| 13 | cashierShift | Reception | `/cashier` (since P18 the overview is above the form; the picture shows only the form) | shift closed (open form) or open (count form) | 1 opening cash + Open shift · 3 counted cash · 5 Close shift |
| 14 | cleaning | Housekeeping (Mulu) | `/housekeeping` (My tasks) | a pending and an in-progress task | 1 "My tasks" tab · 2 "Start" · 3 "Done" · 5 the overview (room status + tasks today) |
| 15 | clockIn | Reception | `/attendance` | not clocked in | 1 "Clock in" · 4 kiosk PIN section |

Find the demo records with the browser helper `api()` (`/front-desk/today` for arrivals / leaving / in house,
`/reservations?status=confirmed` for future bookings, `/invoices?unpaid=1` for an open bill).
