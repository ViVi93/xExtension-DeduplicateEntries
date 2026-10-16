<?php
return [
    'deduplicate' => [
        'name' => 'Deduplicate Entries',
        'description' => 'Prevents new cross-feed copies using canonical URLs while allowing article updates. Original links and GUIDs are preserved.',
        'no_config' => 'Automatic URL matching only. Existing entries are never deleted; similar titles are not automatically suppressed.',
    ],
];
