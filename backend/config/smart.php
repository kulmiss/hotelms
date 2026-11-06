<?php

/*
| Smart hints (claude/smart-features.md, W2): built from the hotel's own data with plain statistics,
| never a paid AI service. Each hint says why, can be dismissed, and never changes data by itself.
| Every key is a Super Admin switch "smart.<key>" (on top of feature.smart_hints).
*/
return [
    'insight_of_the_day' => 'dashboard',
    'tonight' => 'dashboard',
    'no_show_risk' => 'reservation',
    'returning_guest' => 'reservation',
    'best_room' => 'check_in',
    'upgrade' => 'check_in',
    'duplicate_guest' => 'guest_check',
    'cash_prediction' => 'cashier',
    'cleaning_order' => 'housekeeping',
    'staffing' => 'shifts',
    'report_summary' => 'report',
    'plan_usage' => 'rooms_settings',
    'platform_insight' => 'admin',
    'smart_search' => 'search',
];
