<?php
declare(strict_types=1);

namespace core\contract;

class NotFoundException extends \RuntimeException implements PsrNotFoundExceptionInterface
{
}