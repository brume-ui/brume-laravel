<?php

return [
    // Label of the applied-search chip.
    'search' => '搜尋',
    'endpoint_unavailable' => '這個列表沒有提供這項功能。',
    // Label of the applied-scope chip.
    'scope' => '範圍',
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
        'startWith' => '開頭是',
        'endWith' => '結尾是',
    ],
    'action' => [
        // Default toast after an action that returns no Feedback.
        'done' => '已完成「:label」',
        'not_found' => '找不到要操作的資料，可能已經刪除，或不在這個列表裡。',
        'forbidden' => '這筆資料不能執行這項操作。',
        'invalid_id' => '每個 id 都必須是字串或整數。',
    ],
    // Grid::deletable(). {count} stays as is: the page fills it in.
    'delete' => [
        'label' => '刪除',
        'confirm_row' => '刪除這筆資料？',
        'confirm_row_description' => '刪除後無法復原。',
        'confirm_batch' => '刪除所選的 {count} 筆資料？',
        'confirm_batch_label' => '刪除 {count} 筆',
        'done' => '已刪除 :count 筆',
    ],
];
