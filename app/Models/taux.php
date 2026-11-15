<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class taux extends Model
{
    use HasFactory;

    protected $table = 'taux';
    
    protected $fillable = [
        'devise_source',
        'devise_but',
        'valeur',
        'valeur_inverse',
        'date_effet',
        'statut',
    ];

    protected $casts = [
        'valeur' => 'decimal:16',
        'valeur_inverse' => 'decimal:16',
        'date_effet' => 'date',
    ];

    public function deviseSource()
    {
        return $this->belongsTo(devise::class, 'devise_source');
    }

    public function deviseBut()
    {
        return $this->belongsTo(devise::class, 'devise_but');
    }

    public function scopeActif($query)
    {
        return $query->where('statut', 'actif');
    }
}