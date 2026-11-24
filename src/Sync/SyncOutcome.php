<?php

declare(strict_types=1);

namespace MinoxWooCommerce\Sync;

use KBDataSolutions\Sdk\Exception\ConflictException;
use KBDataSolutions\Sdk\Exception\MappingException;
use KBDataSolutions\Sdk\Exception\RateLimitException;
use KBDataSolutions\Sdk\Exception\SdkException;
use KBDataSolutions\Sdk\Exception\ValidationException;
use Throwable;

final readonly class SyncOutcome
{
    public const string SYNCED = 'synced';
    public const string ALREADY_SYNCED = 'already_synced';
    public const string SKIPPED = 'skipped';
    public const string FAILED = 'failed';

    private function __construct(
        public string $status,
        public ?string $message = null,
        public bool $retryable = false,
        public ?int $retryAfterSeconds = null,
    ) {
    }

    public static function synced(): self
    {
        return new self(self::SYNCED);
    }

    public static function alreadySynced(): self
    {
        return new self(self::ALREADY_SYNCED);
    }

    public static function skipped(): self
    {
        return new self(self::SKIPPED);
    }

    public static function failed(string $message, ?int $retryAfterSeconds = null): self
    {
        return new self(self::FAILED, $message, true, $retryAfterSeconds);
    }

    public static function rejected(string $message): self
    {
        return new self(self::FAILED, $message, false);
    }

    public static function fromException(Throwable $exception, string $subject): self
    {
        $message = $subject.': '.$exception->getMessage();

        return match (true) {
            $exception instanceof RateLimitException => self::failed($message, $exception->retryAfterSeconds()),
            $exception instanceof ConflictException => str_ends_with((string) $exception->errorCode(), '_IN_PROGRESS')
                ? self::failed($message)
                : self::rejected($message),
            $exception instanceof ValidationException, $exception instanceof MappingException => self::rejected($message),
            $exception instanceof SdkException => self::failed($message),
            default => self::rejected($message),
        };
    }

    public function isBooked(): bool
    {
        return $this->status === self::SYNCED || $this->status === self::ALREADY_SYNCED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::FAILED;
    }
}
