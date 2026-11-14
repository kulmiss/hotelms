import { useState } from 'react'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it } from 'vitest'
import { Field } from '@/components/ui/Field'
import { date, dateTime, printDate, setCalendarMode } from '@/lib/format'
import '@/i18n'

afterEach(() => setCalendarMode('gregorian'))

function Picker({ start = '' }: { start?: string }) {
  const [v, setV] = useState(start)
  return (
    <>
      <Field label="Arrival" type="date" value={v} onChange={(e) => setV(e.target.value)} />
      <output data-testid="value">{v}</output>
    </>
  )
}

describe('Ethiopian calendar on screens', () => {
  it('shows dates in the chosen calendar', () => {
    expect(date('2026-11-14')).toBe('14 Nov 2026')
    setCalendarMode('ethiopian')
    expect(date('2026-11-14')).toBe('5 Hidar 2019 EC')
    expect(printDate('2026-11-14')).toBe('14 Nov 2026 (5 Hidar 2019)') // prints keep the Gregorian date
    setCalendarMode('both')
    expect(date('2026-11-14')).toBe('14 Nov 2026 · 5 Hidar 2019')
    expect(dateTime('2026-11-14T09:30:00')).toBe('14 Nov 2026 · 5 Hidar 2019, 09:30')
  })

  it('lets you pick a date in EC and keeps a Gregorian value', async () => {
    setCalendarMode('ethiopian')
    render(<Picker />)
    await userEvent.selectOptions(screen.getByLabelText('Day'), '6')
    await userEvent.selectOptions(screen.getByLabelText('Month'), '13') // Pagume
    await userEvent.type(screen.getByLabelText('Year (EC)'), '2019')
    expect(screen.getByTestId('value')).toHaveTextContent('2027-09-11')
    expect(screen.getByText('= 11 Sep 2027')).toBeInTheDocument()
  })

  it('in "both" shows the EC date and can switch to EC typing', async () => {
    setCalendarMode('both')
    render(<Picker start="2026-11-14" />)
    expect(screen.getByText('= 5 Hidar 2019')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Type in Ethiopian calendar' }))
    expect(screen.getByLabelText('Month')).toHaveValue('3')
  })
})
