<?php

return [

    'ddg_base_url' => env('DDG_BASE_URL', 'https://api.duckduckgo.com'),

    'ddg_lang' => env('DDG_LANG', 'es-es'),

    'wiki_base_url' => env('WIKI_BASE_URL', 'https://es.wikipedia.org'),

    'abstract_char_cap' => (int) env('WEB_ABSTRACT_CHAR_CAP', 1500),

    'query_max_length' => (int) env('WEB_QUERY_MAX_LENGTH', 200),

    'timeout' => (int) env('WEB_TIMEOUT', 10),

];
