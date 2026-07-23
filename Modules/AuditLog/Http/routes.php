<?php

// Available to any authenticated user; the controller scopes results to the
// mailboxes each user can view (admins see all). To make it admin-only,
// change roles to ['admin'] here and add ->isAdmin() to the menu guard.
Route::group(['middleware' => ['web', 'auth', 'roles'], 'roles' => ['user', 'admin'], 'prefix' => 'audit'], function () {
    Route::get('/', ['uses' => '\Modules\AuditLog\Http\Controllers\AuditLogController@index'])->name('auditlog.index');
    Route::get('/export', ['uses' => '\Modules\AuditLog\Http\Controllers\AuditLogController@export'])->name('auditlog.export');
});
