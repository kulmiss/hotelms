@php($name = $c['platform_name'] ?: 'HotelMS')
@extends('landing.layout', ['title' => "Terms of use – {$name}", 'description' => "The rules for using {$name}: plans, the Free plan, your data, security and ending the service.", 'path' => '/terms'])

@section('main')
@if (app()->getLocale() !== 'en')
<p class="draft" role="note" style="max-width:720px;margin:24px auto 0">{{ __('This text is in English only for now. An Amharic version will follow after the lawyer has checked it.') }}</p>
@endif
<article class="prose" lang="en">
  <h1>Terms of use</h1>
  <p>Last changed: {{ $updated }}</p>
  <p class="draft" role="note"><strong>Draft – to be checked by a lawyer.</strong> This text explains how the service works today. It will be reviewed before it becomes final.</p>

  <h2>1. Who we are</h2>
  <p>{{ $name }} is online software for hotels and guesthouses: bookings, front desk, bills, housekeeping and reports. "We" means the team that runs {{ $name }}. "You" means the hotel that uses it and the people the hotel adds as staff.@if ($c['email']) You can reach us at <a href="mailto:{{ $c['email'] }}">{{ $c['email'] }}</a>.@endif</p>

  <h2>2. Your account</h2>
  <p>The person who registers a hotel becomes its Hotel Admin and must be allowed to act for the hotel. The information given at sign-up must be true. The email address is confirmed before the first sign-in. Sign-ups that are not confirmed within 7 days are removed. In "approval" mode we check each new hotel before it can start and may refuse a sign-up; we then say why.</p>
  <p>You keep your passwords secret, give each staff member their own account, and remove people who leave. You are responsible for what happens under your hotel's accounts.</p>

  <h2>3. Plans and the Free plan</h2>
  <p>The plans, their limits (rooms, properties, staff, files) and their prices are shown on our website and in the app (Settings → Subscription). The daily work of a hotel – bookings, front desk, check-in and check-out, bills, guests, housekeeping and payment proofs – is in every plan, also the Free plan.</p>
  <ul>
    <li>The Free plan costs nothing and has no end date. It has smaller limits, and the public booking page shows "Powered by {{ $name }}".</li>
    <li>When you reach a limit, the app tells you and nothing is deleted. To get more, you move to a bigger plan.</li>
    <li>We may change the plans and their limits. We tell you at least 30 days before a change that affects your hotel, and a lower limit never deletes data you already have.</li>
    <li>A Free hotel nobody has signed in to for 90 days may be marked as inactive and contacted. Guest data is never deleted automatically because of this.</li>
    <li>The Free plan comes without a promised availability time or response time. Paid plans get faster support as described on the plan.</li>
  </ul>

  <h2>4. Paid plans and payment</h2>
  <p>There is no online payment. We send an invoice for the chosen period (monthly or yearly; yearly includes free months as shown). If a payment is late, the hotel gets a few days of grace; after that it becomes read-only: you can still sign in, look at and download your data, but not add new work until it is paid. Prices are in Ethiopian birr unless the invoice says otherwise.</p>

  <h2>5. Your data</h2>
  <p>The data your hotel puts in {{ $name }} – guests, bookings, bills, staff – belongs to your hotel. For guest data, your hotel decides why and how it is used (the hotel is the "controller") and we only process it for you to run the service (we are the "processor"), as described in the <a href="/privacy">Privacy policy</a>. You can download all your hotel's data at any time.</p>
  <p>You make sure you are allowed to enter the data you enter, for example that guests are told how their data is used and that ID photos are kept only as long as needed (you choose how long in the settings).</p>

  <h2>6. Acceptable use</h2>
  <p>Do not use {{ $name }} for anything illegal, to send spam, to look at other hotels' data, to test or overload the service without our written permission, or to copy the software. We may pause an account that puts other hotels or the service at risk, and we tell you why.</p>

  <h2>7. Security</h2>
  <p>We protect the service with two-step sign-in, encrypted backups, encrypted ID photos, sign-in alerts and logs. Sign-ins and security events are kept for one year so problems can be found. We send security emails to the person concerned – for example a sign-in from a new device, a changed password or a changed sign-in email – and these cannot be switched off. If we learn of a security problem that affects your hotel's data, we tell you without undue delay. Please report security problems as described in <a href="/.well-known/security.txt">security.txt</a>.</p>

  <h2>8. Availability and changes</h2>
  <p>We work to keep {{ $name }} running and backed up, but we cannot promise it is never interrupted. We improve the software often and say what is new in the app. When we remove something important, we tell you first.</p>

  <h2>9. Ending</h2>
  <p>You can stop at any time. Download your data first. When a hotel is closed, its data is kept for 90 days (so it can be reopened) and then deleted for good, except what the law requires us to keep, such as our invoices to you. Backups are overwritten within their normal cycle.</p>

  <h2>10. Liability</h2>
  <p>We are responsible for running the service with care. We are not responsible for losses caused by wrong data entered by the hotel, by misuse of the hotel's accounts, or by events outside our control. Where the law allows, our total liability is limited to what the hotel paid us in the last 12 months.</p>

  <h2>11. Law and questions</h2>
  <p>These terms follow the laws of the Federal Democratic Republic of Ethiopia. We first try to solve any disagreement by talking.@if ($c['email']) Questions: <a href="mailto:{{ $c['email'] }}">{{ $c['email'] }}</a>@endif @if ($c['whatsapp']) or <a href="{{ $c['whatsapp']['href'] }}" rel="noopener">WhatsApp {{ $c['whatsapp']['label'] }}</a>@endif.</p>
</article>
@endsection
