<?php

return [
    'quick_create' => 'Quick create',
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
    // The export endpoint (Grid::exportable()).
    'export' => [
        'too_many' => 'You can export at most :max selected rows at once.',
        // The last row of a file whose export failed part way.
        'interrupted' => 'Export interrupted: :message',
        'interrupted_generic' => 'Export interrupted: the file has only the rows before the error. Export again, or contact an administrator.',
    ],
    // The inline-edit (cell) endpoint.
    'cell' => [
        'not_editable' => 'This column cannot be edited here.',
        'not_found' => 'This row could not be found. It may have been deleted, or is not in this list.',
        'not_text' => 'The :attribute must be text.',
    ],
    // Grid::deletable(). {count} stays as is: the page fills it in.
    'delete' => [
        'label' => 'Delete',
        'confirm_row' => 'Delete this row?',
        'confirm_row_description' => 'This cannot be undone.',
        'confirm_batch' => 'Delete the {count} selected rows?',
        'confirm_batch_label' => 'Delete {count}',
        'done' => 'Deleted 1 row|Deleted :count rows',
        'partial' => 'Deleted :count of :total rows; the others were kept.',
        'none' => 'Nothing was deleted.',
    ],
];
