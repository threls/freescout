<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes that back the Activity Log filters (ARMS-25).
 *
 * activity_logs ships with only a `log_name` index, so filtering/ordering a
 * category by date or causer scans the category. These add:
 *  - (log_name, created_at): the listing is always "in a log, newest first".
 *  - (causer_id, causer_type): the "by user" filter.
 *
 * Guarded + idempotent (mirrors the CustomerFieldSearch index migration).
 */
class AddAuditIndexesToActivityLogs extends Migration
{
    const INDEXES = [
        'activity_logs_log_created_idx' => ['log_name', 'created_at'],
        'activity_logs_causer_idx'      => ['causer_id', 'causer_type'],
    ];

    protected function tableName()
    {
        return config('activitylog.table_name', 'activity_logs');
    }

    public function up()
    {
        if (!Schema::hasTable($this->tableName())) {
            return;
        }

        $table = DB::getTablePrefix().$this->tableName();

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
        if (!Schema::hasTable($this->tableName())) {
            return;
        }

        $table = DB::getTablePrefix().$this->tableName();

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
