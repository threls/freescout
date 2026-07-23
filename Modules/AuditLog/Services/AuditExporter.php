<?php

namespace Modules\AuditLog\Services;

/**
 * Exports the current audit query. CSV is streamed and chunked so a large,
 * lightly-filtered range doesn't load every row at once. PDF renders a bounded
 * slice through dompdf (the same engine ArmsReports uses).
 */
class AuditExporter
{
    /** Hard cap on rows rendered into a PDF, to keep dompdf memory sane. */
    const PDF_MAX_ROWS = 2000;

    /**
     * @param \Illuminate\Database\Eloquent\Builder $builder the AuditQuery builder
     */
    public static function csv($builder, $filename)
    {
        return response()->stream(function () use ($builder) {
            $out = fopen('php://output', 'w');
            // UTF-8 BOM so Excel opens it correctly.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, [__('Date'), __('Agent'), __('Action'), __('Ticket'), __('Mailbox')]);

            $builder->chunk(500, function ($threads) use ($out) {
                foreach ($threads as $thread) {
                    fputcsv($out, self::row($thread));
                }
            });

            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'.csv"',
        ]);
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder $builder the AuditQuery builder
     */
    public static function pdf($builder, $title, $filename)
    {
        if (!class_exists(\Dompdf\Dompdf::class)) {
            abort(500, 'dompdf is not installed — run composer install.');
        }

        $threads = $builder->limit(self::PDF_MAX_ROWS)->get();
        $rows = [];
        foreach ($threads as $thread) {
            $rows[] = self::row($thread);
        }

        $html = \View::make('auditlog::pdf', [
            'title'    => $title,
            'headers'  => [__('Date'), __('Agent'), __('Action'), __('Ticket'), __('Mailbox')],
            'rows'     => $rows,
            'capped'   => count($rows) >= self::PDF_MAX_ROWS,
            'max_rows' => self::PDF_MAX_ROWS,
        ])->render();

        $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false]);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'.pdf"',
        ]);
    }

    /**
     * One export row (shared by CSV and PDF), using the same actor/label
     * rendering as the on-screen table.
     */
    protected static function row($thread)
    {
        $conversation = $thread->conversation;

        return [
            $thread->created_at ? $thread->created_at->format('Y-m-d H:i:s') : '',
            AuditQuery::actorName($thread),
            AuditQuery::actionLabel($thread),
            $conversation ? '#'.$conversation->number : '',
            $conversation && $conversation->mailbox ? $conversation->mailbox->name : '',
        ];
    }
}
