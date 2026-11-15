<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class devise extends Model
{
    use HasFactory;

    protected $table = 'devises';

    protected $fillable = [
        'code',
        'nom',
        'symbole',
    ];

    public function tauxSources()
    {
        return $this->hasMany(taux::class, 'devise_source');
    }

    public function tauxDestinations()
    {
        return $this->hasMany(taux::class, 'devise_but');
    }

    public function caisses()
    {
        return $this->hasMany(caisse::class, 'id_devise');
    }

    public function produitsLignesAchat()
    {
        return $this->hasMany(ligne_approvisionnements::class, 'id_devise');
    }

    public function lignesVente()
    {
        return $this->hasMany(ligne_ventes::class, 'id_devise');
    }

    public function lignesRetour()
    {
        return $this->hasMany(ligne_retours::class, 'id_devise');
    }

    public function lots()
    {
        return $this->hasMany(lots::class, 'id_devise');
    }
}
