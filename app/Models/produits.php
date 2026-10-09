<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class produits extends Model
{
    use HasFactory;

    protected $table = 'produits';

    protected $fillable = [
        'code',
        'nom',
        'description',
        'photo',
        'unite_id',
        'categorie_id',
        'seuil_alerte',
    ];

    protected $casts = [
        'seuil_alerte' => 'decimal:8',
    ];

    public function unite()
    {
        return $this->belongsTo(unites::class, 'unite_id');
    }

    public function unitesAchat()
    {
        return $this->hasMany(produit_unites::class, 'id_produit')->where('type', 'achat')->with(['unite', 'devise']);
    }

    public function unitesVente()
    {
        return $this->hasMany(produit_unites::class, 'id_produit')->where('type', 'vente')->with(['unite', 'devise']);
    }

    public function unites()
    {
        return $this->hasMany(produit_unites::class, 'id_produit');
    }

    public function categorie()
    {
        return $this->belongsTo(categories::class, 'categorie_id');
    }

    public function lignesApprovisionnement()
    {
        return $this->hasMany(ligne_approvisionnements::class, 'id_produit');
    }

    public function lignesVente()
    {
        return $this->hasMany(ligne_ventes::class, 'id_produit');
    }

    public function lots()
    {
        return $this->hasMany(lots::class, 'id_produit');
    }

    public function lignesRetour()
    {
        return $this->hasMany(ligne_retours::class, 'id_produit');
    }
}
