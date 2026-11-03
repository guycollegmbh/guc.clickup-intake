<?php

namespace App\ClickUp;

use RuntimeException;

/**
 * Connecting a ClickUp account was refused or failed. The message is German and shown to the
 * user as is, it never contains a token or a ClickUp response text (R11c).
 */
class ClickUpConnectFailed extends RuntimeException {}
