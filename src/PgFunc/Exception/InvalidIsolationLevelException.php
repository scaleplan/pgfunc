<?php

namespace PgFunc\Exception;

/**
 * Class InvalidIsolationLevelException
 *
 * @package PgFunc\Exception
 */
class InvalidIsolationLevelException extends Database
{
    public const MESSAGE = 'Invalid isolation level.';
    public const CODE = 406;

    /**
     * InvalidIsolationLevelException constructor.
     *
     * @param string $message
     * @param int $code
     * @param \Throwable|null $previous
     */
    public function __construct($message = '', $code = 0, \Throwable $previous = null)
    {
        parent::__construct($message ?: static::MESSAGE, $code ?: static::CODE, $previous);
    }
}
