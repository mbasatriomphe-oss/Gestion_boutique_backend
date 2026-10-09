<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class inventaires extends Model
{
    protected $table = 'inventaires';

    protected $fillable = [
        'id_user',
        'date_inventaire',
        'nombre_produits',
        'nombre_ecarts',
    ];

    protected $casts = [
        'date_inventaire' => 'date',
    ];

    public function lignes()
    {
        return $this->hasMany(lignes_inventaires::class, 'id_inventaire');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'id_user');
    }
}