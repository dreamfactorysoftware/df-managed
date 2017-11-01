<?php


/** Instance routes for DFE */
Route::get('instance/fast-track', '\DreamFactory\Managed\Http\Controllers\InstanceController@getFastTrack');
Route::put('instance/refresh', '\DreamFactory\Managed\Http\Controllers\InstanceController@putRefresh');