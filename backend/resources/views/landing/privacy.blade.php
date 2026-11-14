@php($name = $c['platform_name'] ?: 'HotelMS')
@extends('landing.layout', ['title' => "Privacy policy – {$name}", 'description' => "How {$name} handles hotel and guest data: who decides, what is kept, for how long, and your rights.", 'path' => '/privacy'])

@section('main')
@if (app()->getLocale() !== 'en')
<p class="draft" role="note" style="max-width:720px;margin:24px auto 0">{{ __('This text is in English only for now. An Amharic version will follow after the lawyer has checked it.') }}</p>
@endif
<article class="prose" lang="en">
  <h1>Privacy policy</h1>
  <p>Last changed: {{ $updated }}</p>
  <p class="draft" role="note"><strong>Draft – to be checked by a lawyer.</strong> This text explains how data is handled today. It will be reviewed before it becomes final.</p>

  <h2>1. Two kinds of data, two roles</h2>
  <ul>
    <li><strong>Guest data</strong> (names, phone numbers, ID details and photos, stays, bills): the <strong>hotel</strong> decides why and how it is used – the hotel is the data controller. {{ $name }} keeps and processes it only on the hotel's instructions, to run the service – we are the data processor. Guests who have questions about their data should ask the hotel; we help the hotel answer.</li>
    <li><strong>Account data</strong> of hotels and their staff (names, emails, phone numbers, sign-ins) and our invoices: {{ $name }} decides about this data and is responsible for it.</li>
  </ul>

  <h2>2. What we keep and why</h2>
  <ul>
    <li>Sign-up details (hotel name, owner name, email, phone, city, number of rooms): to create and run the account and to contact you about it.</li>
    <li>Staff accounts: to let each person sign in with the right permissions.</li>
    <li>Sign-ins and security events (time, device, network address): to protect accounts and find misuse. Kept for <strong>one year</strong>, then deleted.</li>
    <li>The activity log of changes in the hotel (who did what, when): so the hotel can check its own work.</li>
    <li>The hotel's work data, including guest data: only to provide the service to that hotel.</li>
    <li>Requests from a hotel's booking page: what the guest typed, for the hotel to answer. We keep no network address or cookie of guests; only a short-lived counter that limits how many requests one network can send.</li>
    <li>Messages to guests (confirmation, the day before arrival, welcome, request answers, and a thank-you): the text, the channel and when it was sent, in the booking's message log. A guest who asks for no messages gets none; the hotel marks this on the guest. The thank-you with the feedback link is sent only when the guest said yes.</li>
    <li>Guest feedback: the stars and the comment the guest sends from the secure link, with the stay it belongs to. It is never changed afterwards; the hotel may add its own note. It is shown on the hotel's booking page only when the guest ticked that it may be shown, with the first name only. The feedback page sets no cookie and keeps no network address.</li>
    <li>Calendar links (Airbnb, Booking.com and others): the links the hotel adds are stored encrypted; we read only busy dates from them. The hotel's own room links show only dates ("Booked" / "Not available") – never names, phone numbers or prices.</li>
  </ul>
  <p>We never sell data, never show ads, and never use a hotel's guest data for our own purposes.</p>

  <h2>3. Cookies</h2>
  <p>Our website sets <strong>no cookies</strong> and uses no tracking or analytics. The app uses only the cookies needed to keep you signed in and to protect forms (security), and stores some screen choices on your device.</p>

  <h2>4. Emails and messages</h2>
  <p>Messages to a hotel's guests are sent on the hotel's behalf and in its name: by email, by SMS through the hotel's own SMS account (the hotel chooses its provider, for example AfroMessage, and pays it), or by WhatsApp or the SMS app that a staff member opens and sends from their own phone. Guests can ask the hotel for no messages at any time.</p>
  <p>We send emails that belong to the service: confirming your email, approval of a sign-up, invoices, and security emails (for example a sign-in from a new device or a changed password). Security emails cannot be switched off. A new hotel sends no emails or Telegram messages until its email is confirmed (and, when we check new hotels, until it is approved). Reports and alerts are sent only to the people and channels the hotel chooses.</p>

  <h2>5. How we protect data</h2>
  <p>Each hotel's data is kept apart from all other hotels. We use encrypted connections, two-step sign-in, encrypted ID photos and secret settings, and encrypted backups every night and every hour. Only a small {{ $name }} team can help a hotel; "log in as hotel" for support needs a written reason and is logged.</p>

  <h2>6. How long we keep data</h2>
  <ul>
    <li>Guest ID photos: as long as the hotel chooses in its settings, then removed automatically.</li>
    <li>Feedback links: they stop working after 60 days. Feedback and the message log stay with the booking, as long as the hotel keeps it.</li>
    <li>Sign-ins and security events: one year.</li>
    <li>Sign-ups whose email was not confirmed: removed after 7 days.</li>
    <li>A closed hotel: kept 90 days so it can be reopened, then deleted for good (backups are overwritten in their normal cycle). Our invoices are kept as long as the law requires.</li>
    <li>Inactive Free hotels are only marked for us to follow up; guest data is never deleted automatically for this reason.</li>
  </ul>

  <h2>7. Who else sees data</h2>
  <p>Only the providers we need to run the service, such as the hosting company, the email service and – if a hotel turns it on – Telegram, the hotel's own SMS provider, and the calendar sites whose links the hotel adds (they read the hotel's room links; we read theirs). They may use data only to provide their service to us. We give data to authorities only when the law requires it, and we tell the hotel when we are allowed to.</p>

  <h2>8. Your rights</h2>
  <p>Hotels can download all their data at any time and correct or delete it in the app. Staff can see their own sign-ins and devices in their profile. Anyone can ask us what account data we hold about them, ask for a correction, or ask for deletion where the law allows.@if ($c['email']) Write to <a href="mailto:{{ $c['email'] }}">{{ $c['email'] }}</a>.@endif We answer within 30 days.</p>

  <h2>9. Changes</h2>
  <p>When this policy changes in a way that matters, we tell hotels in the app or by email before the change applies.</p>
</article>
@endsection
