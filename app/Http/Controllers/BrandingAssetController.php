<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Branding;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Serves an uploaded portal logo (public: it is shown on the sign-in page). */
final class BrandingAssetController extends Controller
{
    public function __invoke(string $file): BinaryFileResponse
    {
        $path = Branding::file($file) ?? abort(404);
        return response()->file($path, [
            'Content-Type' => mime_content_type($path) ?: 'application/octet-stream',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
