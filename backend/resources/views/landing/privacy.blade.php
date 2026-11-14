@php($name = $c['platform_name'] ?: 'HotelMS')
@extends('landing.layout', ['title' => "Privacy policy – {$name}", 'description' => "How {$name} handles hotel and guest data: who decides, what is kept, for how long, and your rights.", 'path' => '/privacy'])

@section('main')
<article class="prose">
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
  </ul>
  <p>We never sell data, never show ads, and never use a hotel's guest data for our own purposes.</p>

  <h2>3. Cookies</h2>
  <p>Our website sets <strong>no cookies</strong> and uses no tracking or analytics. The app uses only the cookies needed to keep you signed in and to protect forms (security), and stores some screen choices on your device.</p>

  <h2>4. Emails and messages</h2>
  <p>We send emails that belong to the service: confirming your email, approval of a sign-up, invoices, and security emails (for example a sign-in from a new device or a changed password). Security emails cannot be switched off. A new hotel sends no emails or Telegram messages until its email is confirmed (and, when we check new hotels, until it is approved). Reports and alerts are sent only to the people and channels the hotel chooses.</p>

  <h2>5. How we protect data</h2>
  <p>Each hotel's data is kept apart from all other hotels. We use encrypted connections, two-step sign-in, encrypted ID photos and secret settings, and encrypted backups every night and every hour. Only a small {{ $name }} team can help a hotel; "log in as hotel" for support needs a written reason and is logged.</p>

  <h2>6. How long we keep data</h2>
  <ul>
    <li>Guest ID photos: as long as the hotel chooses in its settings, then removed automatically.</li>
    <li>Sign-ins and security events: one year.</li>
    <li>Sign-ups whose email was not confirmed: removed after 7 days.</li>
    <li>A closed hotel: kept 90 days so it can be reopened, then deleted for good (backups are overwritten in their normal cycle). Our invoices are kept as long as the law requires.</li>
    <li>Inactive Free hotels are only marked for us to follow up; guest data is never deleted automatically for this reason.</li>
  </ul>

  <h2>7. Who else sees data</h2>
  <p>Only the providers we need to run the service, such as the hosting company, the email service and – if a hotel turns it on – Telegram. They may use data only to provide their service to us. We give data to authorities only when the law requires it, and we tell the hotel when we are allowed to.</p>

  <h2>8. Your rights</h2>
  <p>Hotels can download all their data at any time and correct or delete it in the app. Staff can see their own sign-ins and devices in their profile. Anyone can ask us what account data we hold about them, ask for a correction, or ask for deletion where the law allows.@if ($c['email']) Write to <a href="mailto:{{ $c['email'] }}">{{ $c['email'] }}</a>.@endif We answer within 30 days.</p>

  <h2>9. Changes</h2>
  <p>When this policy changes in a way that matters, we tell hotels in the app or by email before the change applies.</p>
</article>
@endsection
