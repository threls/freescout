<?php

namespace App\Http\Controllers;

use App\ActivityLog;
use App\Misc\Helper;
use App\SendLog;
use App\Thread;
use App\User;
use Illuminate\Http\Request;

class SecureController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Show the application dashboard.
     *
     * @return \Illuminate\Http\Response
     */
    public function dashboard()
    {
        $user = auth()->user();

        $mailboxes = $user->mailboxesCanViewWithSettings();

        // Sort by name.
        $mailboxes = \Eventy::filter('dashboard.mailboxes', $mailboxes->sortBy('name'));

        return view('secure/dashboard', ['mailboxes' => $mailboxes]);
    }

    /**
     * Logs.
     *
     * @return \Illuminate\Http\Response
     */
    public function logs(Request $request)
    {
        function addCol($cols, $col)
        {
            if (!in_array($col, $cols)) {
                $cols[] = $col;
            }

            return $cols;
        }

        // No need to check permissions here, as they are checked in routing

        $names = ActivityLog::select('log_name')->distinct()->pluck('log_name')->toArray();

        $activities = [];
        $cols = [];
        $page_size = 20;
        $name = '';

        if (!empty($request->name)) {
            $name = $request->name;
            $query = ActivityLog::inLog($name)->orderBy('created_at', 'desc');
            // ARMS-25: server-side filtering across the whole log, not just
            // the current 20-row page. The out_emails category reads from
            // send_logs (below) and is filtered there instead.
            if ($name != ActivityLog::NAME_OUT_EMAILS) {
                $this->applyActivityLogFilters($query, $request);
            }
            $activities = $query->paginate($page_size)->appends($request->query());
        } elseif (count($names)) {
            $name = ActivityLog::NAME_OUT_EMAILS;
        }

        if ($name != ActivityLog::NAME_OUT_EMAILS) {
            $logs = [];
            $cols = ['date'];
            foreach ($activities as $activity) {
                $log = [];
                $log['date'] = $activity->created_at;
                if ($activity->causer) {
                    if ($activity->causer_type == 'App\User') {
                        $cols = addCol($cols, 'user');
                        $log['user'] = $activity->causer;
                    } else {
                        $cols = addCol($cols, 'customer');
                        $log['customer'] = $activity->causer;
                    }
                }
                $log['event'] = $activity->getEventDescription();

                $cols = addCol($cols, 'event');

                foreach ($activity->properties as $property_name => $property_value) {
                    if (!is_string($property_value)) {
                        $property_value = json_encode($property_value);
                    }
                    $log[$property_name] = $property_value;
                    $cols = addCol($cols, $property_name);
                }

                $logs[] = $log;
            }
        } else {
            // Outgoing emails are displayed from send log
            $logs = [];
            $cols = [
                'date',
                'type',
                'email',
                'status',
                'message',
                'user',
                'customer',
            ];

            $activities_query = SendLog::orderBy('created_at', 'desc');
            if ($request->get('thread_id')) {
                $activities_query->where('thread_id', $request->get('thread_id'));
            }
            // ARMS-25: send_logs has its own columns, so it filters on date
            // range + email match rather than the user/event fields above.
            $this->applySendLogFilters($activities_query, $request);
            $activities = $activities_query->paginate($page_size)->appends($request->query());

            foreach ($activities as $record) {
                $conversation = '';
                if ($record->thread_id) {
                    $conversation = Thread::find($record->thread_id);
                }

                $status = $record->getStatusName();
                if ($record->status_message) {
                    $status .= '. '.$record->status_message;
                    if ($record->status == SendLog::STATUS_SEND_ERROR) {
                        $status .= '. Message-ID: '.$record->message_id;
                    }
                }
                if ($record->smtp_queue_id) {
                    $status .= '. SMTP ID: '.$record->smtp_queue_id;
                }

                $logs[] = [
                    'date'          => $record->created_at,
                    'type'          => $record->getMailTypeName(),
                    'email'         => $record->email,
                    'status'        => $status,
                    'message'       => $conversation,
                    'user'          => $record->user,
                    'customer'      => $record->customer,
                ];
            }
        }

        array_unshift($names, ActivityLog::NAME_OUT_EMAILS);
        array_push($names, ActivityLog::NAME_APP_LOGS);

        if (!in_array($name, $names)) {
            $names[] = $name;
        }

        return view('secure/logs', [
            'logs'          => $logs,
            'names'         => $names,
            'current_name'  => $name,
            'cols'          => $cols,
            'activities'    => $activities,
            'filters'       => $this->logFilterState($request),
            'event_options' => ($name != ActivityLog::NAME_OUT_EMAILS) ? $this->logEventOptions($name) : [],
            'log_users'     => User::nonDeleted()->orderBy('first_name')->get(),
        ]);
    }

    /**
     * ARMS-25 audit-log filters. Kept read-only — none of this changes how
     * entries are recorded.
     */
    protected function applyActivityLogFilters($query, Request $request)
    {
        if ($request->filled('f_user')) {
            $query->where('causer_type', 'App\User')->where('causer_id', (int) $request->input('f_user'));
        }
        if ($request->filled('f_event')) {
            $query->where('description', $request->input('f_event'));
        }
        $this->applyLogDateRange($query, $request);
        if ($request->filled('f_q')) {
            $like = '%'.$this->escapeLogLike($request->input('f_q')).'%';
            $query->where(function ($w) use ($like) {
                $w->where('description', 'like', $like)
                    ->orWhere('properties', 'like', $like);
            });
        }
    }

    protected function applySendLogFilters($query, Request $request)
    {
        $this->applyLogDateRange($query, $request);
        if ($request->filled('f_q')) {
            $query->where('email', 'like', '%'.$this->escapeLogLike($request->input('f_q')).'%');
        }
    }

    protected function applyLogDateRange($query, Request $request)
    {
        if ($request->filled('f_from')) {
            try {
                $query->where('created_at', '>=', \Carbon\Carbon::parse($request->input('f_from'))->startOfDay());
            } catch (\Throwable $e) {
                // Unparseable date → ignore, don't 500.
            }
        }
        if ($request->filled('f_to')) {
            try {
                $query->where('created_at', '<=', \Carbon\Carbon::parse($request->input('f_to'))->endOfDay());
            } catch (\Throwable $e) {
                // Unparseable date → ignore, don't 500.
            }
        }
    }

    protected function escapeLogLike($term)
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }

    protected function logFilterState(Request $request)
    {
        return [
            'f_user'  => $request->input('f_user'),
            'f_event' => $request->input('f_event'),
            'f_from'  => $request->input('f_from'),
            'f_to'    => $request->input('f_to'),
            'f_q'     => $request->input('f_q'),
        ];
    }

    /**
     * Distinct event descriptions present in this log, mapped to their human
     * labels via the model's own resolver, for the Event filter dropdown.
     */
    protected function logEventOptions($name)
    {
        $descriptions = ActivityLog::inLog($name)
            ->select('description')->distinct()->pluck('description')->filter()->values();

        $options = [];
        foreach ($descriptions as $description) {
            $log = new ActivityLog();
            $log->description = $description;
            $options[$description] = $log->getEventDescription();
        }
        asort($options);

        return $options;
    }

    /**
     * Logs page submitted.
     */
    public function logsSubmit(Request $request)
    {
        // No need to check permissions here, as they are checked in routing

        $name = '';
        if (!empty($request->name)) {
            //$activities = ActivityLog::inLog($request->name)->orderBy('created_at', 'desc')->get();
            $name = $request->name;
        } elseif (count($names = ActivityLog::select('log_name')->distinct()->get()->pluck('log_name'))) {
            $name = ActivityLog::NAME_OUT_EMAILS;
            // $activities = ActivityLog::inLog($names[0])->orderBy('created_at', 'desc')->get();
            // $name = $names[0];
        }

        switch ($request->action) {
            case 'clean':
                if ($name && $name != ActivityLog::NAME_OUT_EMAILS) {
                    ActivityLog::where('log_name', $name)->delete();
                    \Session::flash('flash_success_floating', __('Log successfully cleared'));
                }
                break;
        }

        return redirect()->route('logs', ['name' => $name]);
    }

    /**
     * Upload files and images.
     */
    public function upload(Request $request)
    {
        // 'jpg','gif','png'
        $response = [
            'status' => 'error',
            'msg'    => '', // this is error message
        ];

        $user = auth()->user();

        if (!$user) {
            $response['msg'] = __('Please login to upload file');
        }

        if (!$request->hasFile('file') || !$request->file('file')->isValid() || !$request->file) {
            $response['msg'] = __('Error occurred uploading file');
        }

        if (!$response['msg']) {

            $uploaded_file_path = '';
            try {
                $uploaded_file_path = Helper::uploadFile($request->file);
            } catch (\Exception $e) {
                if ($e->getCode() == \Helper::EXCEPTION_NOT_ALLOWED_FILE_EXTENSION) {
                    $response['msg'] = $e->getMessage();
                }
            }

            if (!$response['msg']) {
                $filename = basename($uploaded_file_path);

                if ($uploaded_file_path) {
                    $response['status'] = 'success';
                    $response['url'] = Helper::uploadedFileUrl($filename);
                } else {
                    $response['msg'] = __('Error occurred uploading file');
                }
            }
        }

        return \Response::json($response);
    }
}
