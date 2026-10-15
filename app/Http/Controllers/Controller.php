<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use InvalidArgumentException;
use PDOException;
use RuntimeException;
use Throwable;

abstract class Controller
{
    /** Redirect with a success message (legacy ok()). */
    protected function ok(string $path, string $message): RedirectResponse
    {
        return redirect($path)->with('flash', ['type' => 'success', 'message' => $message]);
    }

    /** Redirect with an error message (legacy fail()); back to the previous page by default. */
    protected function fail(string $message, ?string $path = null): RedirectResponse
    {
        $redirect = $path === null ? back() : redirect($path);
        return $redirect->with('flash', ['type' => 'danger', 'message' => $message]);
    }

    protected function warn(string $path, string $message): RedirectResponse
    {
        return redirect($path)->with('flash', ['type' => 'warning', 'message' => $message]);
    }

    /**
     * Expected validation failures (domain RuntimeException / InvalidArgumentException, duplicate keys)
     * go back to the form with a message, optionally attached to a field. Anything else is rethrown.
     *
     * @param array<string, string> $fieldPatterns field => regex on the message
     */
    protected function failKeepingInput(Throwable $error, ?string $path = null, array $fieldPatterns = []): RedirectResponse
    {
        $message = self::operatorErrorMessage($error);
        if ($message === null) {
            throw $error;
        }
        $redirect = $path === null ? back() : redirect($path);
        $errors = ['_form' => $message];
        foreach ($fieldPatterns as $field => $pattern) {
            if (preg_match($pattern, $message)) {
                $errors = [$field => $message];
                break;
            }
        }
        return $redirect->withErrors($errors)->with('flash', ['type' => 'danger', 'message' => $message]);
    }

    public static function operatorErrorMessage(Throwable $error): ?string
    {
        if ($error instanceof RuntimeException || $error instanceof InvalidArgumentException) {
            return $error->getMessage();
        }
        if ($error instanceof PDOException && (string) $error->getCode() === '23000') {
            return preg_match("/Duplicate entry '([^']*)'/", $error->getMessage(), $match)
                ? 'The value "' . $match[1] . '" is already in use. Choose a different one.'
                : 'This change conflicts with an existing record.';
        }
        return null;
    }
}
