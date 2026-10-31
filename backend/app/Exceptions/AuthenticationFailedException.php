<?php

namespace App\Exceptions;

/**
 * Deliberately generic (section 8 "Login": "Generic message on bad
 * credentials") - carries no detail about which part was wrong, and the
 * controller translates it to the same 401 response whether the shop
 * code, username, password, or account state was the actual problem.
 */
class AuthenticationFailedException extends \RuntimeException {}
