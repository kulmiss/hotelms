# HotelMS – developer documentation

Everything a developer needs to understand, run, change and ship HotelMS.
Also readable **inside the app**: Super Admin → **Developer docs** (the Owner and the tech team; platform permission `docs.view`).
The same page shows the **deployment guide PDFs** (`deploy-guide/pdf/`): read them in the page, open them in a new tab or download them.
Read in this order the first time:

| # | Document | What you learn |
|---|---|---|
| 1 | [architecture.md](architecture.md) | The big picture: apps, request flow, multi-hotel isolation, permissions, money, time, languages, design rules |
| 2 | [local-setup.md](local-setup.md) | Run it on your laptop, the demo hotel, tests, CI, everyday commands |
| 3 | [backend.md](backend.md) | Laravel code map: folders, middleware, services, scheduler, commands, conventions |
| 4 | [frontend.md](frontend.md) | React code map: routing, data loading, UI kit, i18n, offline, tests |
| 5 | [data-model.md](data-model.md) | Every table, grouped, with the important columns |
| 6 | [api.md](api.md) | API conventions and every route group with its permission |
| 7 | [features.md](features.md) | Each feature: business rules, where the code is, what tests cover it |
| 8 | [recipes.md](recipes.md) | Step-by-step: add a setting, permission, page, report, automation, migration… |
| 9 | [hosting.md](hosting.md) | Put it online (cPanel or VPS), updates, backups, speed |

**Product decisions** (why things work the way they do) live in the claude.ai Project "HotelMS":
`decisions.md`, `workflows.md` (business rules Q1–Q15), `permissions.md`, `database-schema.md`,
`reports.md`, `automation.md`, `smart-features.md`, `super-admin.md`, `plans.md`, `screen-list.md`.
When code and those documents disagree, ask the owner before changing either.

**Staff guides** (for hotel staff, in very easy English) are inside the app: Help → How to use.
Their text is in `frontend/src/i18n/locales/en.guide.json`; which guide shows to whom is in
`frontend/src/lib/guides.ts`; their pictures are in `frontend/public/guide/` (see [recipes.md](recipes.md)).

> **Rule:** every pull request that changes behaviour also updates these documents
> and, when staff see the change, the staff guide. (Also written in `CLAUDE.md`.)
