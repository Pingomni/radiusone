<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Controllers\Controller;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class OperatorErrorMessageTest extends TestCase
{
    private static function pdoError(string $sqlState, string $message): PDOException
    {
        $error = new PDOException($message);
        (new \ReflectionProperty(PDOException::class, 'code'))->setValue($error, $sqlState);
        return $error;
    }

    public function test_duplicate_keys_are_explained_and_other_database_errors_are_not_shown(): void
    {
        $this->assertSame('The value "alice" is already in use. Choose a different one.',
            Controller::operatorErrorMessage(self::pdoError('23000', "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'alice' for key 'username'")));
        $this->assertSame('This change conflicts with an existing record.',
            Controller::operatorErrorMessage(self::pdoError('23000', 'SQLSTATE[23000]: Integrity constraint violation: 1452 Cannot add or update a child row')));
        $this->assertNull(Controller::operatorErrorMessage(self::pdoError('42S02', "SQLSTATE[42S02]: Base table or view not found: 1146 Table 'radius.x' doesn't exist")));
        $this->assertSame('Invalid billing date.', Controller::operatorErrorMessage(new RuntimeException('Invalid billing date.')));
    }
}
