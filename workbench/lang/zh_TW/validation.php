<?php

// The workbench's Chinese validation messages (Laravel ships English only), so the 422 of an
// inline edit reads in the admin's language. Common rules only; others fall back to English.
return [
    'accepted' => ':attribute 必須接受。',
    'array' => ':attribute 必須是陣列。',
    'between' => [
        'array' => ':attribute 必須有 :min 到 :max 個項目。',
        'numeric' => ':attribute 必須介於 :min 到 :max 之間。',
        'string' => ':attribute 必須是 :min 到 :max 個字。',
    ],
    'boolean' => ':attribute 只能是開啟或關閉。',
    'date' => ':attribute 不是有效的日期。',
    'date_format' => ':attribute 的格式必須是 :format。',
    'decimal' => ':attribute 必須有 :decimal 位小數。',
    'digits' => ':attribute 必須是 :digits 位數字。',
    'email' => ':attribute 必須是有效的電子郵件地址。',
    'exists' => '選擇的 :attribute 不存在。',
    'gt' => [
        'numeric' => ':attribute 必須大於 :value。',
        'string' => ':attribute 必須多於 :value 個字。',
    ],
    'gte' => [
        'numeric' => ':attribute 必須大於或等於 :value。',
        'string' => ':attribute 至少要 :value 個字。',
    ],
    'in' => '選擇的 :attribute 無效。',
    'integer' => ':attribute 必須是整數。',
    'list' => ':attribute 必須是清單。',
    'lt' => [
        'numeric' => ':attribute 必須小於 :value。',
        'string' => ':attribute 必須少於 :value 個字。',
    ],
    'lte' => [
        'numeric' => ':attribute 必須小於或等於 :value。',
        'string' => ':attribute 最多 :value 個字。',
    ],
    'max' => [
        'array' => ':attribute 最多 :max 個項目。',
        'file' => ':attribute 不能大於 :max KB。',
        'numeric' => ':attribute 不能大於 :max。',
        'string' => ':attribute 最多 :max 個字。',
    ],
    'min' => [
        'array' => ':attribute 至少要 :min 個項目。',
        'file' => ':attribute 至少要 :min KB。',
        'numeric' => ':attribute 不能小於 :min。',
        'string' => ':attribute 至少要 :min 個字。',
    ],
    'not_in' => '選擇的 :attribute 無效。',
    'numeric' => ':attribute 必須是數字。',
    'present' => ':attribute 必須存在。',
    'regex' => ':attribute 的格式不正確。',
    'required' => '請填寫 :attribute。',
    'size' => [
        'array' => ':attribute 必須是 :size 個項目。',
        'numeric' => ':attribute 必須是 :size。',
        'string' => ':attribute 必須是 :size 個字。',
    ],
    'string' => ':attribute 必須是文字。',
    'unique' => ':attribute 已經有人使用。',
    'url' => ':attribute 必須是有效的網址。',

    'attributes' => [],
];
