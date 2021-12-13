<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MaintenanceTechnician extends Model
{
    use HasFactory;

    protected $fillable=["status"];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function workOrder()
    {
        return $this->hasMany(WorkOrder::class);
    }
}
