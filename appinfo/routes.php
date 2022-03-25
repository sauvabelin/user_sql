<?php

use OCA\UserSQL\AppInfo\Application;

$application = new Application();
$application->registerRoutes(
    $this, [
        "routes" => [
            [
                "name" => "settings#verifyDbConnection",
                "url" => "/settings/db/verify",
                "verb" => "POST"
            ],
            [
                "name" => "settings#saveProperties",
                "url" => "/settings/properties",
                "verb" => "POST"
            ],
            [
                "name" => "settings#clearCache",
                "url" => "/settings/cache/clear",
                "verb" => "POST"
            ],
            [
                "name" => "settings#tableAutocomplete",
                "url" => "/settings/autocomplete/table",
                "verb" => "POST"
            ],
            [
                "name" => "settings#userTableAutocomplete",
                "url" => "/settings/autocomplete/table/user",
                "verb" => "POST"
            ],
            [
                "name" => "settings#userGroupTableAutocomplete",
                "url" => "/settings/autocomplete/table/user_group",
                "verb" => "POST"
            ],
            [
                "name" => "settings#groupTableAutocomplete",
                "url" => "/settings/autocomplete/table/group",
                "verb" => "POST"
            ],
            [
                "name" => "settings#cryptoParams",
                "url" => "/settings/crypto/params",
                "verb" => "GET"
            ],
        ],
        "ocs" => [
            [
                "name" => "GroupChange#syncGroup",
                "url" => "/api/sync",
                "verb" => "GET"
            ]
        ]
    ]
);
