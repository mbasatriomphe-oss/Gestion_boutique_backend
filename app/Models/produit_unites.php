<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class produit_unites extends Model
{
    protected $table = 'produit_unites';

    protected $fillable = [
        'id_produit',
        'id_unite',
        'type',
        'facteur',
        'prix',
        'id_devise',
    ];

    protected $casts = [
        'facteur' => 'decimal:8',
        'prix' => 'decimal:8',
    ];

    public function unite()
    {
        return $this->belongsTo(unites::class, 'id_unite');
    }

    public function produit()
    {
        return $this->belongsTo(produits::class, 'id_produit');
    }

    public function devise()
    {
        return $this->belongsTo(devise::class, 'id_devise');
    }
}