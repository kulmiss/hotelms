<?php

/*
| Super Admin switches (decisions.md W4 part 1). Levels: everyone → plan → one hotel; the most
| specific wins. "default" is used when no switch is set (null for modules and limits = the plan decides).
| type: bool or int (limits). Add every new feature here so it can ship switched off.
*/
return [
    // Optional modules (the plan decides unless a switch says otherwise; the Hotel Admin still turns them on/off)
    'module.housekeeping' => ['type' => 'bool', 'group' => 'modules', 'default' => null],
    'module.cashier_close' => ['type' => 'bool', 'group' => 'modules', 'default' => null],
    'module.shifts' => ['type' => 'bool', 'group' => 'modules', 'default' => null],
    'module.attendance' => ['type' => 'bool', 'group' => 'modules', 'default' => null],

    // Features
    'feature.hourly_stays' => ['type' => 'bool', 'group' => 'features', 'default' => true],
    'feature.day_use' => ['type' => 'bool', 'group' => 'features', 'default' => true],
    'feature.multi_currency' => ['type' => 'bool', 'group' => 'features', 'default' => true],
    'feature.custom_reports' => ['type' => 'bool', 'group' => 'features', 'default' => true],
    'feature.telegram' => ['type' => 'bool', 'group' => 'features', 'default' => true],
    'feature.offline' => ['type' => 'bool', 'group' => 'features', 'default' => true],
    'feature.setup_walkthrough' => ['type' => 'bool', 'group' => 'features', 'default' => true],
    'feature.smart_hints' => ['type' => 'bool', 'group' => 'features', 'default' => true],
    'feature.automations' => ['type' => 'bool', 'group' => 'features', 'default' => true],
    'feature.beta' => ['type' => 'bool', 'group' => 'features', 'default' => false],

    // Limits (empty = the plan's limit)
    'limit.rooms' => ['type' => 'int', 'group' => 'limits', 'default' => null],
    'limit.branches' => ['type' => 'int', 'group' => 'limits', 'default' => null],
    'limit.staff' => ['type' => 'int', 'group' => 'limits', 'default' => null],

    // Emergency
    'emergency.maintenance' => ['type' => 'bool', 'group' => 'emergency', 'default' => false],
    'emergency.read_only' => ['type' => 'bool', 'group' => 'emergency', 'default' => false],
];
