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
        $key = 'uploads/' . $path;
        if (PersistentStorage::usesS3() && PersistentStorage::exists($key)) {
            return redirect()->away(PersistentStorage::temporaryUrl($key, now()->addMinutes(10)));
        }

        $imageResponse = ImagePathHandler::render($request);
        if ($imageResponse !== null) {
            return $imageResponse;
        }

        abort(404);
    }
}
