<?php

namespace App\Services\Flint\Ask;

use RuntimeException;

/** The conversation does not exist, or it is another user's. */
class AskFlintConversationNotFound extends RuntimeException {}
