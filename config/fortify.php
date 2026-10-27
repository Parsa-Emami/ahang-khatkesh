<?php

use Laravel\Fortify\Features;

return [
    'guard' => 'web', 'username' => 'email', 'email' => 'email',
    'passwords' => 'users', 'views' => false, 'home' => '/',
    'lowercase_usernames' => true, 'middleware' => ['web'],
    'limiters' => ['login' => 'login', 'two-factor' => 'two-factor'],
    'features' => [Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true])],
];
