<?php

return [
    // Label of the applied-search chip.
    'search' => '搜尋',
    'endpoint_unavailable' => '這個列表沒有提供這項功能。',
    // An id in an action request or a cell URL that cannot be a key of the grid's model.
    'invalid_id' => 'id 的格式不正確：只能是這個列表的資料編號。',
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
        'too_many' => '一次最多只能操作 :max 筆。',
    ],
    // The export endpoint (Grid::exportable()).
    'export' => [
        'too_many' => '一次最多只能匯出 :max 筆。',
        // The last row of a file whose export failed part way.
        'interrupted' => '匯出中斷：:message',
        'interrupted_generic' => '匯出中斷，檔案只有前面的資料。請再匯出一次，或聯絡管理員。',
    ],
    // The inline-edit (cell) endpoint.
    'cell' => [
        'not_editable' => '這個欄位不能直接編輯。',
        'not_found' => '找不到這筆資料，可能已經刪除，或不在這個列表裡。',
        'not_text' => ':attribute 必須是文字。',
    ],
    // Grid::deletable(). {count} stays as is: the page fills it in.
    'delete' => [
        'label' => '刪除',
        'confirm_row' => '刪除這筆資料？',
        'confirm_row_description' => '刪除後無法復原。',
        'confirm_batch' => '刪除所選的 {count} 筆資料？',
        'confirm_batch_label' => '刪除 {count} 筆',
        'done' => '已刪除 :count 筆',
        'partial' => '已刪除 :count 筆，另外 :kept 筆沒有刪除。',
        'none' => '沒有刪除任何資料。',
    ],
];
