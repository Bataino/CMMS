<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WorkOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'maintenance_technician_id',
        'work_request_id',
        'admin_id',
        'type',
        'description',
        'date',
        'hour',
    ];

    public function workRequest()
    {
        return $this->belongsTo(WorkRequest::class);
    }

    public function admin()
    {
        return $this->belongsTo(Admin::class);
    }

    public function maintenanceTechnician()
    {
        return $this->belongsTo(MaintenanceTechnician::class);
    }

    public function  workOrderLogs(){
        return $this->hasMany(WorkOrderLog::class);
    }

    public function interventionReport(){
        return $this->belongsTo(InterventionReport::class);
    }
}
