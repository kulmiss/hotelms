<?php

/*
| Guest messages (owner, 14 Nov 2026). The hotel edits each one in Settings → Guest messages (English and Amharic).
| {placeholders} are filled in; a line whose placeholder is empty is left out (e.g. no Wi-Fi set → no Wi-Fi line).
| consent: "unless_opt_out" = sent unless the guest said no; "ticked" = only when the guest ticked yes.
| The Amharic texts are machine-made and need a native review (docs/i18n/am-review.csv).
*/
return [
    'placeholders' => ['guest_name', 'hotel_name', 'booking_code', 'arrive', 'leave', 'nights', 'total', 'paid', 'balance', 'check_in_time', 'hotel_phone', 'address', 'wifi_name', 'wifi_password', 'breakfast', 'whatsapp', 'menu_link', 'feedback_link'],

    'messages' => [
        'confirmation' => [
            'consent' => 'unless_opt_out', 'when' => 'The booking is confirmed',
            'en' => ['subject' => 'Booking confirmed: {booking_code}', 'body' => "Dear {guest_name}, your booking at {hotel_name} is confirmed.\nArrive: {arrive}\nLeave: {leave}\nTotal: {total} · paid: {paid}\nQuestions? Call us on {hotel_phone}."],
            'am' => ['subject' => 'ማስያዣው ተረጋግጧል፦ {booking_code}', 'body' => "ውድ {guest_name}፣ በ{hotel_name} ያስያዙት ተረጋግጧል።\nመድረሻ፦ {arrive}\nመነሻ፦ {leave}\nጠቅላላ፦ {total} · የተከፈለ፦ {paid}\nጥያቄ ካለዎት በ{hotel_phone} ይደውሉልን።"],
        ],
        'pre_arrival' => [
            'consent' => 'unless_opt_out', 'when' => 'The day before arrival (from 10:00)',
            'en' => ['subject' => 'See you tomorrow: {booking_code}', 'body' => "Dear {guest_name}, we look forward to welcoming you tomorrow at {hotel_name}.\nCheck-in from {check_in_time}.\nAddress: {address}\nWhatsApp: {whatsapp}\nQuestions? Call us on {hotel_phone}."],
            'am' => ['subject' => 'ነገ እንገናኝ፦ {booking_code}', 'body' => "ውድ {guest_name}፣ ነገ በ{hotel_name} ልንቀበልዎ በጉጉት እንጠብቃለን።\nመግቢያ ከ{check_in_time} ጀምሮ።\nአድራሻ፦ {address}\nዋትስአፕ፦ {whatsapp}\nጥያቄ ካለዎት በ{hotel_phone} ይደውሉልን።"],
        ],
        'welcome' => [
            'consent' => 'unless_opt_out', 'when' => 'At check-in',
            'en' => ['subject' => 'Welcome to {hotel_name}', 'body' => "Welcome, {guest_name}!\nWi-Fi: {wifi_name} · password: {wifi_password}\nBreakfast: {breakfast}\nOur menu: {menu_link}\nReception on WhatsApp: {whatsapp}\nPhone: {hotel_phone}\nEnjoy your stay."],
            'am' => ['subject' => 'እንኳን ወደ {hotel_name} በደህና መጡ', 'body' => "እንኳን በደህና መጡ፣ {guest_name}!\nዋይፋይ፦ {wifi_name} · የይለፍ ቃል፦ {wifi_password}\nቁርስ፦ {breakfast}\nምናሌያችን፦ {menu_link}\nእንግዳ መቀበያ በዋትስአፕ፦ {whatsapp}\nስልክ፦ {hotel_phone}\nመልካም ቆይታ።"],
        ],
        'thank_you' => [
            'consent' => 'ticked', 'when' => 'At check-out, when the guest said yes',
            'en' => ['subject' => 'Thank you for staying at {hotel_name}', 'body' => "Dear {guest_name}, thank you for staying with us.\nHow was your stay? Tell us in one minute: {feedback_link}\nWe hope to see you again."],
            'am' => ['subject' => 'በ{hotel_name} ስለቆዩ እናመሰግናለን', 'body' => "ውድ {guest_name}፣ ከእኛ ጋር ስለቆዩ እናመሰግናለን።\nቆይታዎ እንዴት ነበር? በአንድ ደቂቃ ይንገሩን፦ {feedback_link}\nእንደገና እንደምናይዎ ተስፋ እናደርጋለን።"],
        ],
        'request_received' => [
            'consent' => 'unless_opt_out', 'when' => 'A guest sends a request on the booking page',
            'en' => ['subject' => 'We got your request – {hotel_name}', 'body' => "Dear {guest_name}, thank you for your request at {hotel_name}.\nArrive: {arrive}\nLeave: {leave}\nWe will call you to confirm. Nothing is booked or paid yet.\nPhone: {hotel_phone}"],
            'am' => ['subject' => 'ጥያቄዎ ደርሶናል – {hotel_name}', 'body' => "ውድ {guest_name}፣ በ{hotel_name} ስላቀረቡት ጥያቄ እናመሰግናለን።\nመድረሻ፦ {arrive}\nመነሻ፦ {leave}\nለማረጋገጥ እንደውልልዎታለን። እስካሁን ምንም አልተያዘም ወይም አልተከፈለም።\nስልክ፦ {hotel_phone}"],
        ],
        'request_declined' => [
            'consent' => 'unless_opt_out', 'when' => 'Reception declines a booking-page request',
            'en' => ['subject' => 'Your request – {hotel_name}', 'body' => "Dear {guest_name}, we are sorry: we have no room for {arrive} – {leave}.\nCall us on {hotel_phone} for other dates."],
            'am' => ['subject' => 'ጥያቄዎ – {hotel_name}', 'body' => "ውድ {guest_name}፣ ይቅርታ፦ ከ{arrive} – {leave} ክፍል የለንም።\nለሌሎች ቀኖች በ{hotel_phone} ይደውሉልን።"],
        ],
    ],
];
