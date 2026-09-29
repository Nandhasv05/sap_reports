<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 29/09/2026
 * DESCRIPTION : Client error from the table preference API (carries HTTP status, error code and field errors)
 */
final class TablePreferenceException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $errors = []
    ) {
        parent::__construct($message);
    }
}
