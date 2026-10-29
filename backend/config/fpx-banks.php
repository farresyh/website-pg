<?php

// ADR-130: the FPX bank list is JSON so scripts/sync-shared-files.mjs can copy
// it to the storefront and the partner portal. Display order = file order.
return json_decode(file_get_contents(__DIR__.'/fpx-banks.json'), true, flags: JSON_THROW_ON_ERROR);
