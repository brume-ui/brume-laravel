<?php

return [
    'required' => ':attribute 為必填。',
    'email' => ':attribute 必須是有效的電子郵件地址。',
    'url' => ':attribute 必須是有效的網址。',
    'integer' => ':attribute 必須是整數。',
    'numeric' => ':attribute 必須是數字。',
    'regex' => ':attribute 格式不正確。',
    'in' => ':attribute 選擇的值無效。',
    'array' => ':attribute 必須是陣列。',
    'string' => ':attribute 必須是字串。',
    'boolean' => ':attribute 必須是布林值。',
    'date_format' => ':attribute 必須符合 :format 格式。',
    'after_or_equal' => ':attribute 必須晚於或等於 :date。',
    'decimal' => ':attribute 必須有 :decimal 位小數。',
    'unique' => ':attribute 已經存在。',
    'min' => [
        'numeric' => ':attribute 至少 :min。',
        'string' => ':attribute 至少 :min 個字元。',
        'array' => ':attribute 至少 :min 個項目。',
    ],
    'max' => [
        'numeric' => ':attribute 不得超過 :max。',
        'string' => ':attribute 不得超過 :max 個字元。',
        'array' => ':attribute 不得超過 :max 個項目。',
    ],
];
