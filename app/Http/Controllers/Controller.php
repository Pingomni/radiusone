<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use PDOException;
use RuntimeException;
use Throwable;

abstract class Controller
{
    /**
     * Request input shaped like a classic HTML form post, which is what app/Support expects:
     * unchecked boxes (false/null) are absent, true is '1', numbers are strings. Files are left out.
     */
    protected function formInput(Request $request): array
    {
        $normalise = static function (array $values) use (&$normalise): array {
            $out = [];
            foreach ($values as $key => $value) {
                if ($value === false || $value === null) {
                    continue;
                }
                $out[$key] = match (true) {
                    $value === true => '1',
                    is_int($value), is_float($value) => (string) $value,
                    is_array($value) => $normalise($value),
                    default => $value,
                };
            }
            return $out;
        };
        return $normalise($request->except(array_keys($request->allFiles())));
    }

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
     * @param  array<string, string>  $fieldPatterns  field => regex on the message
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
        // PDOException extends RuntimeException, so it is checked first: other database errors are not operator messages.
        if ($error instanceof PDOException) {
            if ((string) $error->getCode() !== '23000') {
                return null;
            }
            return preg_match("/Duplicate entry '([^']*)'/", $error->getMessage(), $match)
                ? 'The value "' . $match[1] . '" is already in use. Choose a different one.'
                : 'This change conflicts with an existing record.';
        }
        if ($error instanceof RuntimeException || $error instanceof InvalidArgumentException) {
            return $error->getMessage();
        }
        return null;
    }
}
