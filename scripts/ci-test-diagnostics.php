<?php

// Emit only testcase identities; assertion values and environment are intentionally excluded.
$report = $argv[1] ?? '';
$document = new DOMDocument;
$previous = libxml_use_internal_errors(true);
$loaded = is_file($report) && $document->load($report, LIBXML_NONET);
libxml_clear_errors();
libxml_use_internal_errors($previous);
if (! $loaded) {
    echo "::error::Test command failed; JUnit report unavailable. See the full test log.\n";
    exit(0);
}
$xpath = new DOMXPath($document);
$failed = $xpath->query('//testcase[failure or error]');
foreach ($failed as $test) {
    $name = $test->getAttribute('classname').'::'.$test->getAttribute('name');
    $name = str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $name);
    echo '::error title=Test failed::'.$name.PHP_EOL;
}
if ($failed->length === 0) {
    echo "::error::Test command failed without a failed testcase in JUnit. See the full test log.\n";
}
