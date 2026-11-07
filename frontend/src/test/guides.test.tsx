import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import en from '@/i18n/locales/en.guide.json'
import { GUIDE, guideFor, guidePicture } from '@/lib/guides'
import { makeMe, mockApi } from './mockApi'
import { renderApp } from './renderApp'

describe('staff guides', () => {
  it('has words for every topic and section', () => {
    const topics = en.topics as Record<string, { title: string; steps: string[] }>
    for (const s of GUIDE) {
      expect(en.sections[s.key as keyof typeof en.sections], s.key).toBeTruthy()
      for (const tp of s.topics) {
        expect(topics[tp.key]?.title, tp.key).toBeTruthy()
        expect(topics[tp.key]?.steps.length, tp.key).toBeGreaterThan(0)
      }
    }
  })

  it('has a picture file for every topic that says it has one', () => {
    const withPic = GUIDE.flatMap((s) => s.topics).filter((tp) => tp.pic)
    expect(withPic.length).toBeGreaterThan(10)
    const files = Object.keys(import.meta.glob('../../public/guide/*.webp')).map((f) => f.replace('../../public', ''))
    for (const tp of withPic) expect(files, tp.key).toContain(guidePicture(tp.key))
  })

  it('shows each person only what they may do', () => {
    const can = (perms: string[]) => (p?: string | readonly string[]) => !p || (typeof p === 'string' ? perms.includes(p) : p.some((x) => perms.includes(x)))
    const keys = (perms: string[], modules: string[] = []) => guideFor(can(perms), modules).flatMap((s) => s.topics.map((tp) => tp.key))

    const cleaner = keys(['housekeeping.view', 'housekeeping.own_tasks'], ['housekeeping'])
    expect(cleaner).toContain('cleaning')
    expect(cleaner).not.toContain('walkIn')
    expect(keys(['housekeeping.view'])).not.toContain('cleaning') // the hotel has housekeeping off
    expect(keys(['frontdesk.walk_in'])).toContain('walkIn')
    expect(keys([])).toEqual(['signIn', 'lockScreen', 'search', 'lists', 'bell', 'profile', 'branch'])
  })

  it('opens the guides on the Help page and finds one', async () => {
    mockApi({ 'GET /me': { body: makeMe({}, ['frontdesk.view', 'frontdesk.walk_in']) }, 'GET /support': { body: { data: [] } } })
    renderApp('/help')
    expect(await screen.findByText('Walk-in (guest without a booking)')).toBeInTheDocument()
    expect(screen.queryByText('Add staff')).not.toBeInTheDocument()
    expect(screen.getByAltText(/Walk-in \(guest without a booking\)/)).toHaveAttribute('src', '/guide/walkIn.webp')
    await userEvent.type(screen.getByLabelText('Find a guide'), 'walk-in')
    expect(screen.queryByText('Sign in and sign out')).not.toBeInTheDocument()
  })
})
