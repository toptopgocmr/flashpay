<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReconciliationReport extends Model
{
    protected $table = 'reconciliation_reports';
    protected $guarded = ['id'];


    protected function casts(): array
    {
        return ['results' => 'array', 'report_date' => 'date'];
    }
}
