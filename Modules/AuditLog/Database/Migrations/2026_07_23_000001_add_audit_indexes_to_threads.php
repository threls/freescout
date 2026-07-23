<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes that back the cross-ticket audit query (ARMS-25).
 *
 * `threads` holds every message/note/reply in the system and has no index on
 * `type`, `action_type` or `created_by_user_id`, so a query that pulls only
 * the line-item audit rows across all conversations would full-scan it. These
 * two composite indexes make both the default listing (type + newest-first)
 * and the agent filter sargable. `created_at` alone is already indexed
 * natively; the mailbox/ticket filters ride on `conversations`' own indexes.
 *
 * Guarded + idempotent so it is safe to re-run and to reverse (mirrors the
 * CustomerFieldSearch index migration).
 */
class AddAuditIndexesToThreads extends Migration
{
    const INDEXES = [
        // (type, created_at): the base listing scans type = TYPE_LINEITEM
        // ordered by created_at desc — this covers both in one index.
        'threads_audit_type_created_idx'  => ['type', 'created_at'],
        // (created_by_user_id, created_at): the "by agent" filter.
        'threads_audit_actor_created_idx' => ['created_by_user_id', 'created_at'],
    ];

    public function up()
    {
        if (!Schema::hasTable('threads')) {
            return;
        }

        $table = DB::getTablePrefix().'threads';

        foreach (self::INDEXES as $name => $columns) {
            $index = DB::getTablePrefix().$name;
            $cols = implode(', ', $columns);

            if (\Helper::isPgSql()) {
                if (!$this->pgIndexExists($index)) {
                    DB::statement('CREATE INDEX '.$index.' ON '.$table.' ('.$cols.')');
                }
            } else {
                if (!$this->mysqlIndexExists($table, $index)) {
                    DB::statement('ALTER TABLE '.$table.' ADD INDEX '.$index.' ('.$cols.')');
                }
            }
        }
    }

    public function down()
    {
        if (!Schema::hasTable('threads')) {
            return;
        }

        $table = DB::getTablePrefix().'threads';

        foreach (self::INDEXES as $name => $columns) {
            $index = DB::getTablePrefix().$name;

            if (\Helper::isPgSql()) {
                if ($this->pgIndexExists($index)) {
                    DB::statement('DROP INDEX '.$index);
                }
            } else {
                if ($this->mysqlIndexExists($table, $index)) {
                    DB::statement('ALTER TABLE '.$table.' DROP INDEX '.$index);
                }
            }
        }
    }

    protected function mysqlIndexExists($table, $indexName)
    {
        $rows = DB::select('SHOW INDEX FROM '.$table.' WHERE Key_name = ?', [$indexName]);

        return count($rows) > 0;
    }

    protected function pgIndexExists($indexName)
    {
        $rows = DB::select('SELECT 1 FROM pg_indexes WHERE indexname = ?', [$indexName]);

        return count($rows) > 0;
    }
}
