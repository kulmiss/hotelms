import type { ReactNode } from 'react'
import { Badge, type Tone } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Field } from '@/components/ui/Field'

// Developer page: every building block in one place, for checking light/dark and touch sizes.
const colors = ['bg', 'surface', 'sunken', 'border', 'text', 'muted', 'accent', 'accent-soft']
const roomStates = ['occupied', 'available', 'dirty', 'clean', 'maintenance']
const tones: Tone[] = ['ok', 'warn', 'info', 'danger', 'grey', 'accent']

function Section({ title, children }: { title: string; children: ReactNode }) {
  return (
    <Card className="flex flex-col gap-4">
      <h2 className="text-lg font-semibold">{title}</h2>
      {children}
    </Card>
  )
}

export function DesignSystemPage() {
  return (
    <div className="mx-auto flex max-w-5xl flex-col gap-6">
      <h1 className="text-2xl font-bold">Design system</h1>

      <Section title="Colours">
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
          {colors.map((c) => (
            <div key={c} className="flex items-center gap-2 text-sm">
              <span className="size-8 rounded-xl border border-border" style={{ background: `var(--${c})` }} />
              {c}
            </div>
          ))}
        </div>
      </Section>

      <Section title="Room states">
        <div className="flex flex-wrap gap-3">
          {roomStates.map((s) => (
            <span key={s} className="flex items-center gap-2 text-sm capitalize">
              <span className="size-4 rounded" style={{ background: `var(--room-${s})` }} />
              {s}
            </span>
          ))}
        </div>
      </Section>

      <Section title="Buttons (44px minimum, 52px for main actions)">
        <div className="flex flex-wrap gap-3">
          <Button>Primary</Button>
          <Button variant="secondary">Secondary</Button>
          <Button variant="ghost">Ghost</Button>
          <Button variant="danger">Danger</Button>
          <Button size="lg">Check in</Button>
          <Button disabled>Disabled</Button>
        </div>
      </Section>

      <Section title="Badges">
        <div className="flex flex-wrap gap-2">
          {tones.map((tone) => (
            <Badge key={tone} tone={tone}>
              {tone}
            </Badge>
          ))}
        </div>
      </Section>

      <Section title="Form fields">
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Guest name" placeholder="e.g. Abebe Kebede" />
          <Field label="Phone" type="tel" hint="Include the country code, e.g. +251" />
        </div>
      </Section>
    </div>
  )
}
