<?php

return [
    'required' => ':attribute 为必填。',
    'email' => ':attribute 必须是有效的电子邮件地址。',
    'url' => ':attribute 必须是有效的网址。',
    'integer' => ':attribute 必须是整数。',
    'numeric' => ':attribute 必须是数字。',
    'regex' => ':attribute 格式不正确。',
    'in' => ':attribute 选择的值无效。',
    'array' => ':attribute 必须是数组。',
    'string' => ':attribute 必须是字符串。',
    'boolean' => ':attribute 必须是布尔值。',
    'date_format' => ':attribute 必须符合 :format 格式。',
    'after_or_equal' => ':attribute 必须晚于或等于 :date。',
    'decimal' => ':attribute 必须有 :decimal 位小数。',
    'unique' => ':attribute 已经存在。',
    'min' => [
        'numeric' => ':attribute 至少 :min。',
        'string' => ':attribute 至少 :min 個字元。',
        'array' => ':attribute 至少 :min 個項目。',
    ],
    'max' => [
        'numeric' => ':attribute 不得超过 :max。',
        'string' => ':attribute 不得超过 :max 個字元。',
        'array' => ':attribute 不得超过 :max 個項目。',
    ],
];
