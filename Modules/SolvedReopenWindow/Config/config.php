<?php

return [
    /*
     * How many days a Solved ticket may be reopened by a customer reply before
     * a reply instead starts a brand-new ticket (ARMS-21). Calendar days — the
     * Workflows module has no business-day mode, so this matches the Pending
     * reminder windows (ARMS-20). Override per environment with
     * SOLVED_REOPEN_WINDOW_DAYS. env() is read here (config-file scope) so the
     * value survives `config:cache` in production.
     */
    'days' => (int) env('SOLVED_REOPEN_WINDOW_DAYS', 7),
];
