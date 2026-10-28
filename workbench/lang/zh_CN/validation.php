<?php

// The workbench's Chinese validation messages (Laravel ships English only), so the 422 of an
// inline edit reads in the admin's language. Common rules only; others fall back to English.
return [
    'accepted' => ':attribute必须接受。',
    'array' => ':attribute必须是数组。',
    'between' => [
        'array' => ':attribute必须有 :min 到 :max 个项目。',
        'numeric' => ':attribute必须介于 :min 到 :max 之间。',
        'string' => ':attribute必须是 :min 到 :max 个字。',
    ],
    'boolean' => ':attribute只能是开启或关闭。',
    'date' => ':attribute不是有效的日期。',
    'date_format' => ':attribute的格式必须是 :format。',
    'decimal' => ':attribute必须有 :decimal 位小数。',
    'digits' => ':attribute必须是 :digits 位数字。',
    'email' => ':attribute必须是有效的电子邮件地址。',
    'exists' => '选择的:attribute不存在。',
    'gt' => [
        'numeric' => ':attribute必须大于 :value。',
        'string' => ':attribute必须多于 :value 个字。',
    ],
    'gte' => [
        'numeric' => ':attribute必须大于或等于 :value。',
        'string' => ':attribute至少要 :value 个字。',
    ],
    'in' => '选择的:attribute无效。',
    'integer' => ':attribute必须是整数。',
    'list' => ':attribute必须是列表。',
    'lt' => [
        'numeric' => ':attribute必须小于 :value。',
        'string' => ':attribute必须少于 :value 个字。',
    ],
    'lte' => [
        'numeric' => ':attribute必须小于或等于 :value。',
        'string' => ':attribute最多 :value 个字。',
    ],
    'max' => [
        'array' => ':attribute最多 :max 个项目。',
        'file' => ':attribute不能大于 :max KB。',
        'numeric' => ':attribute不能大于 :max。',
        'string' => ':attribute最多 :max 个字。',
    ],
    'min' => [
        'array' => ':attribute至少要 :min 个项目。',
        'file' => ':attribute至少要 :min KB。',
        'numeric' => ':attribute不能小于 :min。',
        'string' => ':attribute至少要 :min 个字。',
    ],
    'not_in' => '选择的:attribute无效。',
    'numeric' => ':attribute必须是数字。',
    'present' => ':attribute必须存在。',
    'regex' => ':attribute的格式不正确。',
    'required' => '请填写:attribute。',
    'size' => [
        'array' => ':attribute必须是 :size 个项目。',
        'numeric' => ':attribute必须是 :size。',
        'string' => ':attribute必须是 :size 个字。',
    ],
    'string' => ':attribute必须是文字。',
    'unique' => ':attribute已经有人使用。',
    'url' => ':attribute必须是有效的网址。',

    'attributes' => [],
];
