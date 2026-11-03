<?php

namespace App\Microsoft;

use RuntimeException;

/**
 * A Microsoft login that must not lead to a session. The message is shown to the user (German).
 */
class LoginRejected extends RuntimeException {}
