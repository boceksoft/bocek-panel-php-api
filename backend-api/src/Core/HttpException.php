<?php

declare(strict_types=1);

namespace App\Core;

/*
 * İş kuralı / doğrulama / yetki hataları için tek tip exception.
 * Front controller bunu standart hata zarfına çevirir.
 */
class HttpException extends \RuntimeException
{
    /** @var string */
    private $errorCode;

    /** @var int */
    private $httpStatus;

    public function __construct(
        string $message,
        string $errorCode = 'ERROR',
        int $httpStatus = 400,
        \Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->errorCode = $errorCode;
        $this->httpStatus = $httpStatus;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}
