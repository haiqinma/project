<?php

namespace App\Http\Controllers;

use App\Exceptions\ImagePathHandler;
use App\Services\PersistentStorage;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class StorageObjectController extends Controller
{
    public function __invoke(Request $request, string $path): Response
    {
        $imageResponse = ImagePathHandler::render($request);
        if ($imageResponse !== null) {
            return $imageResponse;
        }

        if (str_contains($path, '/crop/')) {
            abort(404);
        }

        $key = 'uploads/' . $path;
        if (PersistentStorage::usesS3() && PersistentStorage::exists($key)) {
            return redirect()->away(PersistentStorage::temporaryUrl($key, now()->addMinutes(10)));
        }

        abort(404);
    }
}
