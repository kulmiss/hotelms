<?php

/*
| Ready-made automations (claude/automation.md). Each one:
|   group    – where it is listed
|   default  – on for a new hotel?
|   to       – who is told (permissions; the Hotel Admin has all of them). "guest" = an email to the guest.
|   options  – numbers the hotel can change
|   module   – only when that optional module is on
|   platform – runs for the HotelMS team (only a Super Admin switch, not in hotel settings)
| Every key is also a Super Admin switch "automation.<key>" (everyone / plan / hotel).
*/
return [
    // Bookings and guests
    'booking_confirmation' => ['group' => 'bookings', 'default' => true, 'to' => ['guest']],
    'deposit_reminder' => ['group' => 'bookings', 'default' => true, 'to' => ['reservations.edit'], 'options' => ['hours' => 3]],
    'booking_expired' => ['group' => 'bookings', 'default' => true, 'to' => ['creator']],
    'arrival_reminder' => ['group' => 'bookings', 'default' => false, 'to' => ['guest']],
    'no_show_suggestion' => ['group' => 'bookings', 'default' => true, 'to' => ['reservations.no_show'], 'options' => ['hours' => 4]],
    'welcome_back' => ['group' => 'bookings', 'default' => true, 'to' => ['frontdesk.check_in']],
    'guest_birthday' => ['group' => 'bookings', 'default' => true, 'to' => ['frontdesk.view']],
    'blacklist_warning' => ['group' => 'bookings', 'default' => true, 'to' => ['guests.blacklist'], 'urgent' => true],
    'vip_arrival' => ['group' => 'bookings', 'default' => true, 'to' => ['checkout.approve_balance'], 'options' => ['nights' => 7]],
    'cancellation_refund' => ['group' => 'bookings', 'default' => true, 'to' => ['approvals.decide']],

    // Front desk and money
    'shift_start_brief' => ['group' => 'money', 'default' => true, 'to' => ['assigned'], 'module' => 'shifts'],
    'checkout_balance_reminder' => ['group' => 'money', 'default' => true, 'to' => ['frontdesk.check_out']],
    'outstanding_follow_up' => ['group' => 'money', 'default' => true, 'to' => ['reports.outstanding'], 'options' => ['days' => 7]],
    'close_cashier_reminder' => ['group' => 'money', 'default' => true, 'to' => ['assigned'], 'module' => 'cashier_close'],
    'cashier_short' => ['group' => 'money', 'default' => true, 'to' => ['cashier.approve'], 'module' => 'cashier_close', 'urgent' => true],
    'exchange_rate_reminder' => ['group' => 'money', 'default' => true, 'to' => ['settings.exchange_rates'], 'options' => ['time' => '10:00']],
    'large_discount' => ['group' => 'money', 'default' => true, 'to' => ['checkout.approve_balance'], 'options' => ['amount' => '1000']],
    'void_after_payment' => ['group' => 'money', 'default' => true, 'to' => ['hotel_admin'], 'urgent' => true],
    'transfer_without_reference' => ['group' => 'money', 'default' => true, 'to' => ['receiver']],

    // Rooms and housekeeping
    'checkout_cleaning' => ['group' => 'rooms', 'default' => true, 'to' => ['housekeeping.own_tasks'], 'module' => 'housekeeping'],
    'room_ready' => ['group' => 'rooms', 'default' => true, 'to' => ['frontdesk.check_in']],
    'arrival_room_not_ready' => ['group' => 'rooms', 'default' => true, 'to' => ['frontdesk.check_in', 'housekeeping.assign'], 'options' => ['hours' => 2]],
    'long_maintenance' => ['group' => 'rooms', 'default' => true, 'to' => ['rooms.maintenance'], 'options' => ['days' => 3]],
    'hourly_cleaning' => ['group' => 'rooms', 'default' => true, 'to' => ['housekeeping.own_tasks'], 'module' => 'housekeeping'],
    'stayover_not_cleaned' => ['group' => 'rooms', 'default' => true, 'to' => ['housekeeping.assign'], 'module' => 'housekeeping', 'options' => ['time' => '15:00']],

    // Staff
    'late_for_shift' => ['group' => 'staff', 'default' => true, 'to' => ['staff.manage'], 'module' => 'attendance', 'options' => ['minutes' => 15]],
    'understaffed_shift' => ['group' => 'staff', 'default' => true, 'to' => ['shifts.manage'], 'module' => 'shifts'],
    'new_device' => ['group' => 'staff', 'default' => true, 'to' => ['hotel_admin']],

    // Revenue and reports
    'price_up_suggestion' => ['group' => 'revenue', 'default' => true, 'to' => ['approvals.decide'], 'options' => ['occupancy' => 85, 'percent' => 10]],
    'price_down_suggestion' => ['group' => 'revenue', 'default' => true, 'to' => ['approvals.decide'], 'options' => ['occupancy' => 30, 'percent' => 10]],
    'target_risk' => ['group' => 'revenue', 'default' => true, 'to' => ['hotel_admin'], 'options' => ['percent' => 85]],
    'daily_report' => ['group' => 'revenue', 'default' => true, 'to' => ['reports.revenue']],
    'weekly_summary' => ['group' => 'revenue', 'default' => true, 'to' => ['hotel_admin']],

    // Subscription (HotelMS side)
    'subscription_reminder' => ['group' => 'platform', 'default' => true, 'to' => ['hotel_admin'], 'platform' => true],
    'overdue_notice' => ['group' => 'platform', 'default' => true, 'to' => ['hotel_admin'], 'platform' => true],
    'trial_ending_sales' => ['group' => 'platform', 'default' => true, 'to' => ['platform:hotels.create'], 'platform' => true],
    'inactive_hotel' => ['group' => 'platform', 'default' => true, 'to' => ['platform:support.answer'], 'platform' => true, 'options' => ['days' => 7]],
    'stuck_in_setup' => ['group' => 'platform', 'default' => true, 'to' => ['platform:support.answer'], 'platform' => true, 'options' => ['days' => 5]],
];
