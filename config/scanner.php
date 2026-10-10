<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API Key Scanner (opsional)
    |--------------------------------------------------------------------------
    |
    | Jika diisi, semua request ke /api/scan dan /api/scan/export wajib
    | membawa header "Authorization: Bearer <key>" atau "X-Api-Key: <key>".
    | Kosongkan untuk pemakaian lokal tanpa proteksi.
    |
    */

    'api_key' => env('SCAN_API_KEY'),

];
