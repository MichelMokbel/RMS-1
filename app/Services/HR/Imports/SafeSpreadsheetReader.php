<?php

namespace App\Services\HR\Imports;

use App\Support\Imports\SafeSpreadsheetReader as SharedSafeSpreadsheetReader;

/** @deprecated Use the shared import reader for new bounded contexts. */
class SafeSpreadsheetReader extends SharedSafeSpreadsheetReader {}
