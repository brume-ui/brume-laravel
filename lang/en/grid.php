<?php

return [
    // Label of the applied-search chip.
    'search' => 'Search',
    'endpoint_unavailable' => 'This list does not offer that.',
    // An id in an action request or a cell URL that cannot be a key of the grid's model.
    'invalid_id' => 'That id is not valid: it must be the id of a row in this list.',
    // Label of the applied-scope chip.
    'scope' => 'Scope',
    // Between the labels of several chosen options on a chip.
    'separator' => ', ',
    // Operators of a condition-group filter; the chip reads "{operator} {value}".
    'operators' => [
        'equal' => '=',
        'notEqual' => '≠',
        'gt' => '>',
        'lt' => '<',
        'ngt' => '≤',
        'nlt' => '≥',
        'like' => 'contains',
        'ilike' => 'contains',
        'startWith' => 'starts with',
        'endWith' => 'ends with',
    ],
    'action' => [
        // Default toast after an action that returns no Feedback.
        'done' => 'Done: “:label”',
        'not_found' => 'Some of these rows could not be found. They may have been deleted, or are not in this list.',
        'forbidden' => 'This action is not available for that row.',
        'too_many' => 'You can act on at most :max rows at once.',
    ],
    // Grid::deletable(). {count} stays as is: the page fills it in.
    'delete' => [
        'label' => 'Delete',
        'confirm_row' => 'Delete this row?',
        'confirm_row_description' => 'This cannot be undone.',
        'confirm_batch' => 'Delete the {count} selected rows?',
        'confirm_batch_label' => 'Delete {count}',
        'done' => 'Deleted 1 row|Deleted :count rows',
    ],
];
