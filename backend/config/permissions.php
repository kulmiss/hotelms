<?php

/*
| Hotel permissions and default roles (claude/permissions.md, approved).
| HA = Hotel Admin (locked, has everything), BM = Branch Manager,
| RC = Receptionist, HK = Housekeeping, AC = Accountant.
| 'module' marks permissions of an optional module.
*/

$HA = 'hotel_admin';
$BM = 'branch_manager';
$RC = 'receptionist';
$HK = 'housekeeping';
$AC = 'accountant';

return [

    'roles' => [
        $HA => ['name' => 'Hotel Admin', 'max_discount' => '100.00', 'locked' => true],
        $BM => ['name' => 'Branch Manager', 'max_discount' => '20.00'],
        $RC => ['name' => 'Receptionist', 'max_discount' => '5.00'],
        $HK => ['name' => 'Housekeeping', 'max_discount' => '0.00'],
        $AC => ['name' => 'Accountant', 'max_discount' => '0.00'],
    ],

    'groups' => [
        'dashboard' => [
            'dashboard.view' => [$BM, $RC, $AC],
            'dashboard.view_revenue' => [$BM, $AC],
        ],
        'frontdesk' => [
            'frontdesk.view' => [$BM, $RC],
            'frontdesk.walk_in' => [$BM, $RC],
            'frontdesk.check_in' => [$BM, $RC],
            'frontdesk.check_out' => [$BM, $RC],
            'frontdesk.room_move' => [$BM, $RC],
            'fees.waive' => [$BM, $RC],
            'checkout.approve_balance' => [$BM],
        ],
        'reservations' => [
            'reservations.view' => [$BM, $RC, $AC],
            'reservations.create' => [$BM, $RC],
            'reservations.edit' => [$BM, $RC],
            'reservations.cancel' => [$BM, $RC],
            'reservations.no_show' => [$BM, $RC],
            'reservations.change_policy' => [$BM],
        ],
        'guests' => [
            'guests.view' => [$BM, $RC, $AC],
            'guests.create_edit' => [$BM, $RC],
            'guests.blacklist' => [$BM],
            'guests.export' => [],
        ],
        'billing' => [
            'invoices.view' => [$BM, $RC, $AC],
            'invoices.add_charge' => [$BM, $RC, $AC],
            'invoices.discount' => [$BM, $RC],
            'invoices.split' => [$BM, $RC, $AC],
            'invoices.void' => [$BM],
            'payments.take' => [$BM, $RC, $AC],
            'payments.view' => [$BM, $AC],
            'payments.refund' => [$BM],
            'invoices.print' => [$BM, $RC, $AC],
        ],
        'cashier' => [
            'cashier.own_shift' => [$BM, $RC],
            'cashier.view_all' => [$BM, $AC],
            'cashier.approve' => [$BM],
        ],
        'housekeeping' => [
            'housekeeping.view' => [$BM, $RC, $HK],
            'housekeeping.own_tasks' => [$BM, $HK],
            'housekeeping.assign' => [$BM],
            'rooms.change_status' => [$BM, $RC, $HK],
            'rooms.maintenance' => [$BM],
        ],
        'staff' => [
            'staff.view' => [$BM],
            'staff.manage' => [$BM],
            'roles.manage' => [],
            'shifts.manage' => [$BM],
            'shifts.view_own' => [$BM, $RC, $HK, $AC],
            'attendance.clock' => [$BM, $RC, $HK, $AC],
            'attendance.manual' => [$BM],
            'attendance.report' => [$BM],
        ],
        'reports' => [
            'reports.occupancy' => [$BM],
            'reports.revenue' => [$BM, $AC],
            'reports.payments' => [$BM, $AC],
            'reports.outstanding' => [$BM, $AC],
            'reports.tax' => [$AC],
            'reports.guests' => [$BM],
            'reports.audit' => [$BM, $AC],
            'reports.cancellations' => [$BM],
            'reports.export' => [$BM, $AC],
            'reports.my_shift' => [$BM, $RC, $AC],
            'reports.my_work' => [$HK],
            'reports.forecast' => [$BM, $AC],
            'reports.targets_manage' => [],
            'reports.branch_compare' => [],
            'reports.custom' => [$BM, $AC],
            'reports.schedule' => [$BM, $AC],
            'alerts.manage' => [],
            'automations.manage' => [],
            'approvals.decide' => [$BM],
            'reports.accounting_export' => [$AC],
        ],
        'settings' => [
            'settings.hotel' => [],
            'settings.branches' => [],
            'settings.rooms' => [$BM],
            'settings.pricing' => [$BM],
            'settings.policies' => [],
            'settings.money' => [$AC],
            'settings.exchange_rates' => [$BM, $AC],
            'settings.charges' => [$BM],
            'settings.modules' => [],
            'settings.attendance' => [],
            'subscription.view' => [],
            'activity_log.view' => [],
        ],
    ],

    /* Permissions that belong to an optional module (hidden when it is off). */
    'modules' => [
        'cashier' => 'cashier_close',
        'housekeeping' => 'housekeeping',
        'shifts.manage' => 'shifts',
        'shifts.view_own' => 'shifts',
        'attendance.clock' => 'attendance',
        'attendance.manual' => 'attendance',
        'attendance.report' => 'attendance',
        'settings.attendance' => 'attendance',
        'reports.my_work' => 'housekeeping',
    ],

];
