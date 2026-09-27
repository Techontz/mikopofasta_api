<?php

/*
|--------------------------------------------------------------------------
| HRM
|--------------------------------------------------------------------------
|
| default_staff_password: the fixed password a staff account is reset to from
| HRM → All Active Staff → Reset password. It is read from the environment
| only (DEFAULT_STAFF_PASSWORD) and has no default in source; when it is not
| configured the reset endpoint refuses and changes nothing.
|
*/

return [
    'default_staff_password' => env('DEFAULT_STAFF_PASSWORD'),
];
