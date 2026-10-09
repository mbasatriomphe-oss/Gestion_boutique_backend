<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class lignes_inventaires extends Model
{
    protected $table = 'lignes_inventaires';

    protected $fillable = [
        'id_inventaire',
        'id_produit',
        'quantite_attendue',
        'quantite_comptee',
        'ecart',
        'quantites_formats',
    ];

    protected $casts = [
        'quantite_attendue' => 'decimal:8',
        'quantite_comptee' => 'decimal:8',
        'ecart' => 'decimal:8',
        'quantites_formats' => 'array',
    ];

    public function inventaire()
    {
        return $this->belongsTo(inventaires::class, 'id_inventaire');
    }

    public function produit()
    {
        return $this->belongsTo(produits::class, 'id_produit');
    }
}