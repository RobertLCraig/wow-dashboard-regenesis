<?php

use App\Models\User;
use Illuminate\Database\QueryException;

/**
 * Guards the revoked-grant fallback in User::save(). If this predicate ever
 * widens, a genuine query bug starts being swallowed instead of raised.
 * Delete alongside that fallback once the grants are back (board card 0001).
 */
function queryExceptionWithMysqlCode(int $code): QueryException
{
    $pdo = new PDOException('the message');
    $pdo->errorInfo = ['42000', $code, 'command denied'];

    return new QueryException('mysql', 'update `users` set `name` = ?', ['x'], $pdo);
}

it('recognises the revoked-grant error', function () {
    expect(User::isWriteDenied(queryExceptionWithMysqlCode(1142)))->toBeTrue();
});

it('leaves every other query error alone', function () {
    expect(User::isWriteDenied(queryExceptionWithMysqlCode(1146)))->toBeFalse(); // no such table
    expect(User::isWriteDenied(queryExceptionWithMysqlCode(1054)))->toBeFalse(); // no such column
    expect(User::isWriteDenied(queryExceptionWithMysqlCode(1062)))->toBeFalse(); // duplicate key
});

it('treats an exception carrying no driver error code as a real failure', function () {
    $bare = new QueryException('mysql', 'update `users` set `name` = ?', ['x'], new RuntimeException('gone'));
    expect(User::isWriteDenied($bare))->toBeFalse();
});
