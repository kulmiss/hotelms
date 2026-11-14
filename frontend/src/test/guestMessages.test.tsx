import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { GuestMessagesCard } from '@/components/messages/GuestMessagesCard'
import { makeMe, mockApi } from './mockApi'
import { renderApp } from './renderApp'

afterEach(() => vi.unstubAllGlobals())

const log = {
  data: [{ id: 1, key: 'confirmation', channel: 'email', language: 'en', to: 'abebe@guest.test', status: 'sent', error: null, by: null, at: '2026-11-14T09:00:00+03:00' }],
  messages: [
    { key: 'confirmation', allowed: true },
    { key: 'thank_you', allowed: false },
  ],
  channels: { email: true, phone: true, sms: false },
  opt_out: false,
  feedback_consent: false,
  language: null,
}

describe('guest messages', () => {
  it('a booking shows the log, a preview and opens WhatsApp with the text ready', async () => {
    const open = vi.fn()
    vi.stubGlobal('open', open)
    mockApi({
      'GET /me': { body: makeMe({}, ['reservations.view', 'reservations.edit']) },
      'GET /reservations/5/messages': { body: log },
      'GET /reservations/5/messages/preview': { body: { data: { subject: 'Booking confirmed: RES-1', body: 'Dear Abebe', language: 'en' } } },
      'POST /reservations/5/messages': { body: { data: { status: 'opened', link: 'https://wa.me/251911?text=Dear', error: null, text: 'Dear' } } },
    })
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    render(
      <QueryClientProvider client={qc}>
        <GuestMessagesCard reservationId={5} />
      </QueryClientProvider>,
    )
    expect(await screen.findByText('Dear Abebe')).toBeInTheDocument()
    expect(screen.getByText(/abebe@guest.test · automatic/)).toBeInTheDocument()
    expect(screen.getByRole('option', { name: /Thank-you with feedback link \(needs the guest's yes\)/ })).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'WhatsApp' }))
    await vi.waitFor(() => expect(open).toHaveBeenCalledWith('https://wa.me/251911?text=Dear', '_blank', 'noopener'))
  })

  it('settings: guest information, automatic sending and the SMS account note', async () => {
    mockApi({
      'GET /me': { body: makeMe({}, ['settings.hotel']) },
      'GET /settings/guest-messages': {
        body: {
          messages: [{ key: 'confirmation', consent: 'unless_opt_out', en: { subject: 'Booking confirmed: {booking_code}', body: 'Dear {guest_name}', custom: false }, am: { subject: 'ማስያዣው ተረጋግጧል', body: 'ውድ {guest_name}', custom: false } }],
          placeholders: ['guest_name', 'booking_code'],
          auto: { confirmation: { email: true, sms: false } },
          info: { wifi_name: null, wifi_password: null, breakfast: null, whatsapp: null, menu_url: null },
          sms: { provider: 'none', sender: null, identifier: null, url: null, method: 'POST', body: null, has_token: false },
          auto_sms: false,
        },
      },
    })
    renderApp('/settings/guest-messages')
    expect(await screen.findByLabelText('Wi-Fi name')).toBeInTheDocument()
    expect(screen.getAllByText(/Automatic SMS comes with the Starter plan/).length).toBeGreaterThan(0)
    expect(screen.getByLabelText('Booking confirmation – SMS (account)')).toBeDisabled()
    expect(screen.getByDisplayValue('Dear {guest_name}')).toBeInTheDocument()
    expect(screen.getByText(/WhatsApp Business Cloud API/)).toBeInTheDocument()
  })
})
