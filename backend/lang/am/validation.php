<?php

/*
 * Amharic messages for the public booking page (owner, 14 Nov 2026) – needs native review.
 * Anything missing falls back to English.
 */
return [
    'required' => ':attribute ያስፈልጋል።',
    'integer' => ':attribute ቁጥር መሆን አለበት።',
    'numeric' => ':attribute ቁጥር መሆን አለበት።',
    'string' => ':attribute ጽሑፍ መሆን አለበት።',
    'date' => ':attribute ትክክለኛ ቀን መሆን አለበት።',
    'email' => ':attribute ትክክለኛ ኢሜይል መሆን አለበት።',
    'regex' => ':attribute ትክክል አይደለም።',
    'in' => 'የተመረጠው :attribute ትክክል አይደለም።',
    'after' => ':attribute ከ:date በኋላ መሆን አለበት።',
    'after_or_equal' => ':attribute :date ወይም ከዚያ በኋላ መሆን አለበት።',
    'before_or_equal' => ':attribute :date ወይም ከዚያ በፊት መሆን አለበት።',
    'between' => [
        'numeric' => ':attribute ከ:min እስከ :max መሆን አለበት።',
        'string' => ':attribute ከ:min እስከ :max ፊደላት መሆን አለበት።',
    ],
    'min' => [
        'numeric' => ':attribute ቢያንስ :min መሆን አለበት።',
        'string' => ':attribute ቢያንስ :min ፊደላት መሆን አለበት።',
    ],
    'max' => [
        'numeric' => ':attribute ከ:max መብለጥ የለበትም።',
        'string' => ':attribute ከ:max ፊደላት መብለጥ የለበትም።',
    ],
    'attributes' => [
        'branch_id' => 'ቅርንጫፍ',
        'room_type_id' => 'ክፍል',
        'check_in' => 'የመድረሻ ቀን',
        'check_out' => 'የመነሻ ቀን',
        'adults' => 'አዋቂዎች',
        'children' => 'ልጆች',
        'name' => 'ስም',
        'phone' => 'ስልክ',
        'email' => 'ኢሜይል',
        'note' => 'መልዕክት',
    ],
];
