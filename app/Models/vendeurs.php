<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class vendeurs extends Authenticatable
{
    use HasFactory, HasApiTokens, Notifiable;

    protected $table = 'vendeurs';

    protected $fillable = [
        'nom',
        'prenom',
        'code',
        'email',
        'password',
        'telephone',
        'adresse',
    ];

    protected $hidden = [
        'password',
    ];

    public function getRoleAttribute(): string
    {
        return 'vendeur';
    }

    public function ventes()
    {
        return $this->hasMany(ventes::class, 'id_vendeur');
    }

    public function retours()
    {
        return $this->hasMany(retours::class, 'id_vendeur');
    }
}
