<?php
return array(
    'name' => 'Deduplicate Entries',
    'description' => 'Prevents duplicate entries from being added when the same article (by hash) already exists in another feed.',
    'no_config' => 'This extension works automatically. No configuration needed.',
    'warning' => array(
        'duplicate_skipped' => 'Skipped duplicate entry: %s',
    ),
);
