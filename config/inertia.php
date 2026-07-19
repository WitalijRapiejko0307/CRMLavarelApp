<?php

return [
    /*
     * This is the root view that is loaded on the first page visit.
     * See https://inertiajs.com/server-side-setup for more info.
     */
    'root_view' => 'app',

    /*
     * Inertia will put your shared data in a specific key of the page object.
     * Here you can change the default key.
     */
    'shared_key' => 'shared',

    /*
     * Whether to use flash data from the session.
     * If enabled, Inertia will automatically share flash data to the response.
     */
    'ssr' => [
        'enabled' => false,
        'url'     => 'http://127.0.0.1:13714',
    ],

    /*
     * The history state key used to store the current component state in the browser's
     * history state. This is only used when using partial reloads and history mode.
     */
    'history' => [
        'encrypt' => false,
    ],
];
