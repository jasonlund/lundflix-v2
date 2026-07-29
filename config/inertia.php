<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Server Side Rendering
    |--------------------------------------------------------------------------
    |
    | The app does not use SSR. Inertia v3 otherwise renders through Vite
    | whenever public/hot exists, so a running `npm run dev` would make every
    | Inertia test fail on a stray request to the dev server's /__inertia_ssr.
    |
    */

    'ssr' => [
        'enabled' => false,
    ],

];
