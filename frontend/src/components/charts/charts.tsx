import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Area, AreaChart, Bar, BarChart, CartesianGrid, Cell, ComposedChart, Line, Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { date as fmtDate } from '@/lib/format'
import { useReducedMotion } from './kit'

/*
 * Charts on the shadcn chart pattern (Recharts, as in the template). Colours are CSS variables from our tokens,
 * so light and dark mode and the hotel's brand colour work without code here.
 */

const axis = { fontSize: 11, fill: 'var(--muted)' }
const short = (d: string) => fmtDate(d).replace(/ \d{4}$/, '')

/** The tooltip box (card colours, small text). */
function TipBox({ title, rows }: { title: string; rows: { label: string; value: string; color?: string }[] }) {
  return (
    <div className="rounded-lg border border-border bg-surface px-3 py-2 text-xs shadow-md">
      <p className="mb-1 font-medium">{title}</p>
      {rows.map((r) => (
        <p key={r.label} className="flex items-center gap-2 text-muted">
          {r.color && <span aria-hidden className="size-2 rounded-[2px]" style={{ background: r.color }} />}
          <span className="flex-1">{r.label}</span>
          <span className="font-medium text-text tabular-nums">{r.value}</span>
        </p>
      ))}
    </div>
  )
}

/** Small trend line inside a KPI card (no axes, no tooltip). */
export function Sparkline({ values, label }: { values: number[]; label: string }) {
  const reduced = useReducedMotion()
  if (values.length < 2) return null
  const data = values.map((v, i) => ({ i, v }))
  return (
    <div role="img" aria-label={label} className="h-8 w-24 shrink-0">
      <ResponsiveContainer width="100%" height="100%">
        <AreaChart data={data} margin={{ top: 2, right: 0, bottom: 2, left: 0 }}>
          <Area type="monotone" dataKey="v" stroke="var(--chart-1)" strokeWidth={2} fill="var(--chart-soft)" isAnimationActive={!reduced} animationDuration={200} />
        </AreaChart>
      </ResponsiveContainer>
    </div>
  )
}

export type DayPoint = { date: string; occupancy: number; revenue?: number; payments?: number }

/** Revenue bars and the occupancy line, one point per day. A click on a day calls onSelect(date). */
export function OccRevenueChart({
  data,
  metric,
  money,
  onSelect,
}: {
  data: DayPoint[]
  metric: 'both' | 'occupancy' | 'revenue'
  money: (v: number) => string
  onSelect?: (date: string) => void
}) {
  const { t } = useTranslation()
  const reduced = useReducedMotion()
  const showRev = metric !== 'occupancy' && data.some((d) => d.revenue !== undefined)
  const showOcc = metric !== 'revenue'
  return (
    <div role="img" aria-label={t('dash.occRevenueAria', { days: data.length })} className="h-64 w-full">
      <ResponsiveContainer width="100%" height="100%">
        <ComposedChart
          data={data}
          margin={{ top: 8, right: 4, bottom: 0, left: 4 }}
          onClick={(s) => {
            const i = Number(s?.activeIndex)
            if (onSelect && Number.isInteger(i) && data[i]) onSelect(data[i].date)
          }}
          style={{ cursor: onSelect ? 'pointer' : undefined }}
        >
          <CartesianGrid vertical={false} stroke="var(--chart-grid)" />
          <XAxis dataKey="date" tickFormatter={short} tick={axis} tickLine={false} axisLine={false} minTickGap={24} />
          {showRev && <YAxis yAxisId="rev" orientation="left" tick={axis} tickLine={false} axisLine={false} width={56} tickFormatter={(v: number) => compact(v)} />}
          {showOcc && <YAxis yAxisId="occ" orientation="right" domain={[0, 100]} tick={axis} tickLine={false} axisLine={false} width={36} tickFormatter={(v: number) => `${v}%`} />}
          <Tooltip
            cursor={{ fill: 'var(--sunken)', opacity: 0.6 }}
            content={({ active, payload }) => {
              const p = active && payload?.[0] ? (payload[0].payload as DayPoint) : null
              if (!p) return null
              const rows = [{ label: t('dash.kpi.occupancy'), value: `${p.occupancy}%`, color: 'var(--chart-1)' }]
              if (p.revenue !== undefined) rows.push({ label: t('dash.kpi.revenue'), value: money(p.revenue), color: 'var(--chart-soft)' })
              return <TipBox title={fmtDate(p.date)} rows={rows} />
            }}
          />
          {showRev && <Bar yAxisId="rev" dataKey="revenue" fill="var(--chart-soft)" radius={[3, 3, 0, 0]} isAnimationActive={!reduced} animationDuration={200} />}
          {showOcc && <Line yAxisId="occ" type="monotone" dataKey="occupancy" stroke="var(--chart-1)" strokeWidth={2.5} dot={false} activeDot={{ r: 4 }} isAnimationActive={!reduced} animationDuration={200} />}
        </ComposedChart>
      </ResponsiveContainer>
    </div>
  )
}

/** Revenue (brand) and money taken (blue) side by side per day. */
export function RevenuePaymentsChart({ data, money, onSelect }: { data: DayPoint[]; money: (v: number) => string; onSelect?: (date: string) => void }) {
  const { t } = useTranslation()
  const reduced = useReducedMotion()
  return (
    <div role="img" aria-label={t('dash.revPayAria', { days: data.length })} className="h-60 w-full">
      <ResponsiveContainer width="100%" height="100%">
        <BarChart
          data={data}
          margin={{ top: 8, right: 4, bottom: 0, left: 4 }}
          onClick={(s) => {
            const i = Number(s?.activeIndex)
            if (onSelect && Number.isInteger(i) && data[i]) onSelect(data[i].date)
          }}
        >
          <CartesianGrid vertical={false} stroke="var(--chart-grid)" />
          <XAxis dataKey="date" tickFormatter={short} tick={axis} tickLine={false} axisLine={false} minTickGap={24} />
          <YAxis tick={axis} tickLine={false} axisLine={false} width={56} tickFormatter={(v: number) => compact(v)} />
          <Tooltip
            cursor={{ fill: 'var(--sunken)', opacity: 0.6 }}
            content={({ active, payload }) => {
              const p = active && payload?.[0] ? (payload[0].payload as DayPoint) : null
              if (!p) return null
              return (
                <TipBox
                  title={fmtDate(p.date)}
                  rows={[
                    { label: t('dash.kpi.revenue'), value: money(p.revenue ?? 0), color: 'var(--chart-1)' },
                    { label: t('dash.kpi.moneyTaken'), value: money(p.payments ?? 0), color: 'var(--chart-2)' },
                  ]}
                />
              )
            }}
          />
          <Bar dataKey="revenue" fill="var(--chart-1)" radius={[3, 3, 0, 0]} isAnimationActive={!reduced} animationDuration={200} />
          <Bar dataKey="payments" fill="var(--chart-2)" radius={[3, 3, 0, 0]} isAnimationActive={!reduced} animationDuration={200} />
        </BarChart>
      </ResponsiveContainer>
    </div>
  )
}

export type Slice = { key: string; label: string; value: number; color: string }

/** Donut with the total in the middle. A click on a slice calls onSelect(key). */
export function Donut({ slices, center, label, onSelect, size = 132 }: { slices: Slice[]; center: ReactNode; label: string; onSelect?: (key: string) => void; size?: number }) {
  const reduced = useReducedMotion()
  const shown = slices.filter((s) => s.value > 0)
  return (
    <div role="img" aria-label={label} className="relative shrink-0" style={{ width: size, height: size }}>
      <ResponsiveContainer width="100%" height="100%">
        <PieChart>
          <Pie data={[{ key: 'track', value: 1 }]} dataKey="value" innerRadius="68%" outerRadius="100%" fill="var(--sunken)" stroke="none" isAnimationActive={false} />
          <Pie
            data={shown}
            dataKey="value"
            nameKey="label"
            innerRadius="68%"
            outerRadius="100%"
            startAngle={90}
            endAngle={-270}
            paddingAngle={shown.length > 1 ? 1.5 : 0}
            stroke="none"
            isAnimationActive={!reduced}
            animationDuration={250}
            onClick={(_, i) => onSelect?.(shown[i]?.key)}
          >
            {shown.map((s) => (
              <Cell key={s.key} fill={s.color} style={{ cursor: onSelect ? 'pointer' : undefined }} />
            ))}
          </Pie>
        </PieChart>
      </ResponsiveContainer>
      <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center text-center">{center}</div>
    </div>
  )
}

/** Rooms already booked per day ahead (pace). Days at 85%+ use the brand colour. */
export function PaceChart({ data, onSelect }: { data: { date: string; occupancy: number }[]; onSelect?: (date: string) => void }) {
  const { t } = useTranslation()
  const reduced = useReducedMotion()
  return (
    <div role="img" aria-label={t('dash.paceAria', { days: data.length })} className="h-40 w-full">
      <ResponsiveContainer width="100%" height="100%">
        <BarChart
          data={data}
          margin={{ top: 16, right: 0, bottom: 0, left: 0 }}
          onClick={(s) => {
            const i = Number(s?.activeIndex)
            if (onSelect && Number.isInteger(i) && data[i]) onSelect(data[i].date)
          }}
        >
          <XAxis dataKey="date" tickFormatter={(d: string) => t(`common.weekdays.${new Date(`${d}T00:00:00`).getDay() || 7}`)} tick={axis} tickLine={false} axisLine={false} interval={0} />
          <YAxis hide domain={[0, 100]} />
          <Tooltip
            cursor={{ fill: 'var(--sunken)', opacity: 0.6 }}
            content={({ active, payload }) => {
              const p = active && payload?.[0] ? (payload[0].payload as { date: string; occupancy: number }) : null
              return p ? <TipBox title={fmtDate(p.date)} rows={[{ label: t('dash.booked'), value: `${p.occupancy}%` }]} /> : null
            }}
          />
          <Bar dataKey="occupancy" radius={[3, 3, 0, 0]} isAnimationActive={!reduced} animationDuration={200} label={{ position: 'top', fontSize: 10, fill: 'var(--muted)', formatter: (v: unknown) => `${Math.round(Number(v))}%` }}>
            {data.map((d) => (
              <Cell key={d.date} fill={d.occupancy >= 85 ? 'var(--chart-1)' : 'var(--chart-soft)'} />
            ))}
          </Bar>
        </BarChart>
      </ResponsiveContainer>
    </div>
  )
}

/** 1234567 → "1.2M", 45000 → "45k" (axis labels only; amounts in cards use money()). */
export function compact(v: number): string {
  if (Math.abs(v) >= 1_000_000) return `${(v / 1_000_000).toFixed(1).replace(/\.0$/, '')}M`
  if (Math.abs(v) >= 1_000) return `${Math.round(v / 1_000)}k`
  return String(Math.round(v))
}
