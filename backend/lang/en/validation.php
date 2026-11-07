<?php

/*
 * Only what differs from Laravel's own English messages (they are merged).
 * Plain words for the people at the desk: "the amount", not "the payment.amount field".
 */
return [
    'decimal' => 'The :attribute must be a number with :decimal decimal places, like 1500 or 1500.50.',

    'attributes' => [
        'payment.amount' => 'amount',
        'payment.payment_method_id' => 'payment method',
        'payment.reference' => 'reference',
        'payment_method_id' => 'payment method',
        'rooms.*.room_id' => 'room',
        'rooms.*.room_type_id' => 'room type',
        'quick_guest.name' => 'guest name',
        'quick_guest.phone' => 'phone',
    ],
];
