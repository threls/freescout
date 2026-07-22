<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Schema-check cache lifetime
    |--------------------------------------------------------------------------
    |
    | Minutes to cache the customer_customer_field table-existence check
    | (fires on every search keystroke, so it's cached rather than hitting
    | information_schema/pg_catalog each time). The table only appears or
    | disappears when the Crm module is installed/uninstalled, so a longer
    | value is safe; a shorter one just means picking up that change faster
    | without needing an app-level cache clear.
    |
    */

    'cache_minutes' => env('CUSTOMER_FIELD_SEARCH_CACHE_MINUTES', 15),

    /*
    |--------------------------------------------------------------------------
    | Field labels (fallback only)
    |--------------------------------------------------------------------------
    |
    | The "Custom Field" dropdown on Search > Customers/Conversations gets
    | its real field names straight from Crm's own CustomerField model
    | (customer_fields.name) whenever that class is available — which is
    | always true in practice, since this module already requires Crm's
    | customer_customer_field table to do anything at all.
    |
    | This map only kicks in if that class is ever unavailable (a future
    | Crm version restructuring it, say): a Crm customer_field_id to a
    | human label, e.g.:
    |
    |   1 => 'Account Number',
    |   2 => 'ID Card',
    |
    | A field ID present in the data but missing here still shows up in the
    | dropdown in that fallback case, just labelled "Field #<id>".
    |
    */

    'fields' => [],

];
