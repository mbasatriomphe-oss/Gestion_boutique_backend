<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class clients extends Model
{
    use HasFactory;

    protected $table = 'clients';

    protected $fillable = [
        'nom',
        'post_nom',
        'prenom',
        'adresse',
        'ville',
        'pays',
        'contact',
        'email',
        'iduser',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'iduser');
    }

    public function ventes()
    {
        return $this->hasMany(ventes::class, 'id_client');
    }

    public function retours()
    {
        return $this->hasMany(retours::class, 'id_client');
    }
}
