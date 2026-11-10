# shadcn primitives

Parts copied from the shadcn-admin template (`satnaing/shadcn-admin` @ `e16c87f`, MIT – see `THIRD_PARTY_NOTICES`),
built on Radix UI and cmdk. Our colour names differ from shadcn's, so the classes are translated:

| shadcn class | ours |
|---|---|
| `bg-accent` / `text-accent-foreground` (hover, selected) | `bg-sunken` / `text-text` |
| `bg-muted`, `bg-secondary` | `bg-sunken` |
| `ring-ring`, `border-ring` (focus) | `ring-focus`, `border-focus` |
| `bg-popover text-popover-foreground` | `bg-surface text-text` |
| tooltip `bg-primary` (dark in the template) | `bg-text text-bg` |

`bg-primary` stays: it is the hotel brand colour (Super Admin branding).
Touch screens get 44px targets with `pointer-coarse:` (decision W5).

The page-level building blocks (`components/ui`) are restyled on top of these in P3.

`collapsible.tsx` has the template's Collapsible API (`Collapsible`, `CollapsibleTrigger`, `CollapsibleContent`, `data-state`)
but is our own small copy: `@radix-ui/react-collapsible` was removed in #104 (owner). Used by `components/ui/CollapsibleToolbar.tsx`.
