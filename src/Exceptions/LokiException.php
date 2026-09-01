<?php

namespace Omniboost\LaravelLoggingLoki\Exceptions;

/**
 * Marker interface implemented by every exception this package throws.
 *
 * Catch this to handle any Loki failure without caring which one it was:
 *
 *     try {
 *         $client->push($streams);
 *     } catch (LokiException $e) {
 *         // ...
 *     }
 */
interface LokiException extends \Throwable
{
}
