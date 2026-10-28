<?php

// The workbench's Chinese validation messages (Laravel ships English only), so the 422 of an
// inline edit reads in the admin's language. Common rules only; others fall back to English.
return [
    'accepted' => ':attribute必須接受。',
    'array' => ':attribute必須是陣列。',
    'between' => [
        'array' => ':attribute必須有 :min 到 :max 個項目。',
        'numeric' => ':attribute必須介於 :min 到 :max 之間。',
        'string' => ':attribute必須是 :min 到 :max 個字。',
    ],
    'boolean' => ':attribute只能是開啟或關閉。',
    'date' => ':attribute不是有效的日期。',
    'date_format' => ':attribute的格式必須是 :format。',
    'decimal' => ':attribute必須有 :decimal 位小數。',
    'digits' => ':attribute必須是 :digits 位數字。',
    'email' => ':attribute必須是有效的電子郵件地址。',
    'exists' => '選擇的:attribute不存在。',
    'gt' => [
        'numeric' => ':attribute必須大於 :value。',
        'string' => ':attribute必須多於 :value 個字。',
    ],
    'gte' => [
        'numeric' => ':attribute必須大於或等於 :value。',
        'string' => ':attribute至少要 :value 個字。',
    ],
    'in' => '選擇的:attribute無效。',
    'integer' => ':attribute必須是整數。',
    'list' => ':attribute必須是清單。',
    'lt' => [
        'numeric' => ':attribute必須小於 :value。',
        'string' => ':attribute必須少於 :value 個字。',
    ],
    'lte' => [
        'numeric' => ':attribute必須小於或等於 :value。',
        'string' => ':attribute最多 :value 個字。',
    ],
    'max' => [
        'array' => ':attribute最多 :max 個項目。',
        'file' => ':attribute不能大於 :max KB。',
        'numeric' => ':attribute不能大於 :max。',
        'string' => ':attribute最多 :max 個字。',
    ],
    'min' => [
        'array' => ':attribute至少要 :min 個項目。',
        'file' => ':attribute至少要 :min KB。',
        'numeric' => ':attribute不能小於 :min。',
        'string' => ':attribute至少要 :min 個字。',
    ],
    'not_in' => '選擇的:attribute無效。',
    'numeric' => ':attribute必須是數字。',
    'present' => ':attribute必須存在。',
    'regex' => ':attribute的格式不正確。',
    'required' => '請填寫:attribute。',
    'size' => [
        'array' => ':attribute必須是 :size 個項目。',
        'numeric' => ':attribute必須是 :size。',
        'string' => ':attribute必須是 :size 個字。',
    ],
    'string' => ':attribute必須是文字。',
    'unique' => ':attribute已經有人使用。',
    'url' => ':attribute必須是有效的網址。',

    'attributes' => [],
];
