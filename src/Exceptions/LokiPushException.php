<?php

namespace Omniboost\LaravelLoggingLoki\Exceptions;

/**
 * Base class for a failed push to Loki.
 *
 * Extends \RuntimeException so code that already caught \RuntimeException around
 * a push keeps working; catch this (or the LokiException interface) to target
 * this package specifically.
 */
class LokiPushException extends \RuntimeException implements LokiException
{
}
