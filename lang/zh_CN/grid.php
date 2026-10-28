<?php

return [
    // Label of the applied-search chip.
    'search' => '搜索',
    'endpoint_unavailable' => '这个列表没有提供这项功能。',
    // Label of the applied-scope chip.
    'scope' => '范围',
    // Between the labels of several chosen options on a chip.
    'separator' => '、',
    // Operators of a condition-group filter; the chip reads "{operator} {value}".
    'operators' => [
        'equal' => '=',
        'notEqual' => '≠',
        'gt' => '>',
        'lt' => '<',
        'ngt' => '≤',
        'nlt' => '≥',
        'like' => '包含',
        'ilike' => '包含',
        'startWith' => '开头是',
        'endWith' => '结尾是',
    ],
    'action' => [
        // Default toast after an action that returns no Feedback.
        'done' => '已完成「:label」',
        'not_found' => '找不到要操作的数据，可能已经删除，或不在这个列表里。',
        'forbidden' => '这条数据不能执行这项操作。',
        'invalid_id' => '每个 id 都必须是字符串或整数。',
    ],
    // Grid::deletable(). {count} stays as is: the page fills it in.
    'delete' => [
        'label' => '删除',
        'confirm_row' => '删除这条数据？',
        'confirm_row_description' => '删除后无法恢复。',
        'confirm_batch' => '删除所选的 {count} 条数据？',
        'confirm_batch_label' => '删除 {count} 条',
        'done' => '已删除 :count 条',
    ],
];
