<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Services\CdrIngestor;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class FreeswitchCdrController extends Controller
{
    public function __invoke(Request $request, CdrIngestor $ingestor): Response
    {
        // mod_xml_cdr posts form-encoded "cdr=<xml>"; accept a raw XML body too.
        $xml = (string) ($request->input('cdr') ?? $request->getContent());

        try {
            $ingestor->ingest($xml);
        } catch (InvalidArgumentException $e) {
            Log::warning('Rejected FreeSWITCH CDR', ['error' => $e->getMessage()]);

            // 4xx tells mod_xml_cdr not to retry a record that will never parse.
            return response($e->getMessage(), 422);
        }

        return response('', 200);
    }
}
