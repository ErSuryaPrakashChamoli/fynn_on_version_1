<?php

namespace App\Http\Controllers;

use App\Models\OcrDocument;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class OcrDocumentFileController extends Controller
{
    public function __invoke(OcrDocument $ocrDocument)
    {
        abort_unless(auth()->check(), 403);

        $path = Storage::disk('local')->path($ocrDocument->original_path);
        abort_unless(is_file($path), 404);

        return response()->file($path, [
            'Content-Disposition' => 'inline; filename="' . addslashes($ocrDocument->original_name) . '"',
        ]);
    }
}
