<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ligne_approvisionnements extends Model
{
    use HasFactory;

    protected $table = 'ligne_approvisionnements';

    protected $fillable = [
        'id_approvisionnement',
        'id_produit',
        'id_unite',
        'quantite',
        'facteur_conversion',
        'quantite_stock',
        'prix_unitaire',
        'prix_unitaire_stock',
        'prix_vente',
        'prix_vente_stock',
        'id_devise',
        'paye_par_caisse',
    ];

    protected $casts = [
        'quantite' => 'decimal:8',
        'facteur_conversion' => 'decimal:8',
        'quantite_stock' => 'decimal:8',
        'prix_unitaire' => 'decimal:8',
        'prix_unitaire_stock' => 'decimal:8',
        'prix_vente' => 'decimal:8',
        'prix_vente_stock' => 'decimal:8',
        'paye_par_caisse' => 'boolean',
    ];

    public function approvisionnement()
    {
        return $this->belongsTo(approvisionnements::class, 'id_approvisionnement');
    }

    public function produit()
    {
        return $this->belongsTo(produits::class, 'id_produit');
    }

    public function devise()
    {
        return $this->belongsTo(devise::class, 'id_devise');
    }

    public function unite()
    {
        return $this->belongsTo(unites::class, 'id_unite');
    }

    public function lots()
    {
        return $this->hasMany(lots::class, 'id_ligne_approvisionnement');
    }
}
