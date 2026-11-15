<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Organization extends Model
{
    protected $fillable = ['name', 'type', 'address', 'city', 'state', 'zipCode', 'phone', 'email', 'manager', 'employees', 'status', 'description'];

    protected $casts = ['employees' => 'integer'];
}
